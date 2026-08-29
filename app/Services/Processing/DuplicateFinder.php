<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\DTOs\DuplicateGroup;
use App\DTOs\RawEvent;
use App\Models\Event;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Finds clusters of stored events that look like the same real-world event,
 * without changing anything.
 *
 * Reporting is deliberately separated from merging so the nightly command and
 * the admin review screen agree on what a duplicate is: the command merges
 * every group this returns, the screen shows them for a human to confirm.
 *
 * Two passes, mirroring the pipeline's own matching:
 *   1. Events sharing a blocking key exactly.
 *   2. Same-city, same-day events scoring above the configured threshold.
 */
class DuplicateFinder
{
    public function __construct(
        private readonly EventDeduplicator $deduplicator,
        private readonly EventMerger $merger,
    ) {}

    /**
     * Every duplicate cluster in the given scope, exact matches first.
     *
     * @param  Builder<Event>  $query  Scope to search; not mutated.
     * @param  int|null  $limit  Maximum number of groups to return.
     * @return Collection<int, DuplicateGroup>
     */
    public function find(Builder $query, bool $fuzzy = false, ?int $limit = null): Collection
    {
        $groups = $this->byMatchKey($query, $limit);

        if (! $fuzzy || ($limit !== null && $groups->count() >= $limit)) {
            return $groups;
        }

        $alreadyGrouped = $groups
            ->flatMap(fn (DuplicateGroup $group): Collection => $group->events->pluck('id'))
            ->all();

        $remaining = $limit === null ? null : $limit - $groups->count();

        return $groups->concat($this->byScore($query, $remaining, $alreadyGrouped))->values();
    }

    /**
     * Pass 1 — events whose blocking key matches exactly.
     *
     * @param  Builder<Event>  $query
     * @return Collection<int, DuplicateGroup>
     */
    public function byMatchKey(Builder $query, ?int $limit = null): Collection
    {
        $keys = $query->clone()
            ->canonical()
            ->whereNotNull('match_key')
            ->select('match_key')
            ->groupBy('match_key')
            ->havingRaw('count(*) > 1')
            ->orderBy('match_key')
            ->when($limit !== null, fn (Builder $builder): Builder => $builder->limit($limit))
            ->pluck('match_key');

        return $keys
            ->map(function (string $matchKey) use ($query): ?DuplicateGroup {
                $events = $this->rank($query->clone()->canonical()->where('match_key', $matchKey)->get());

                return $events->count() < 2
                    ? null
                    : new DuplicateGroup($matchKey, $events, 'match_key', 1.0);
            })
            ->filter()
            ->values();
    }

    /**
     * Pass 2 — same-city, same-day events that score above the threshold.
     *
     * @param  Builder<Event>  $query
     * @param  list<string>  $exclude  Event ids already reported by pass 1.
     * @return Collection<int, DuplicateGroup>
     */
    public function byScore(Builder $query, ?int $limit = null, array $exclude = []): Collection
    {
        $minimumScore = (float) config('eventpulse.dedup.min_score', 0.75);
        $maxCandidates = (int) config('eventpulse.dedup.max_candidates', 200);

        $buckets = $query->clone()
            ->canonical()
            ->whereNotNull('local_date')
            ->whereNotIn('id', $exclude)
            ->select('city_slug', 'local_date')
            ->groupBy('city_slug', 'local_date')
            ->havingRaw('count(*) > 1')
            ->orderBy('local_date')
            ->get();

        /** @var Collection<int, DuplicateGroup> $groups */
        $groups = collect();

        foreach ($buckets as $bucket) {
            if ($limit !== null && $groups->count() >= $limit) {
                break;
            }

            $events = $query->clone()
                ->canonical()
                ->where('city_slug', $bucket->city_slug)
                ->whereDate('local_date', $bucket->local_date)
                ->whereNotIn('id', $exclude)
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit($maxCandidates)
                ->get();

            $groups = $groups->concat($this->clusterBucket($events, $minimumScore));
        }

        return $limit === null ? $groups->values() : $groups->take($limit)->values();
    }

    /**
     * Order events by how good a canonical row each would make: source
     * priority first, then how complete the row is, then age.
     *
     * @param  Collection<int, Event>  $events
     * @return Collection<int, Event>
     */
    public function rank(Collection $events): Collection
    {
        return $events->sort(fn (Event $a, Event $b): int => $this->compare($a, $b))->values();
    }

    /**
     * The timezone of the city an event belongs to.
     */
    public function timezoneFor(Event $event): string
    {
        /** @var array<string, array{label?: string, timezone?: string}> $cities */
        $cities = config('eventpulse.cities', []);

        foreach ($cities as $key => $city) {
            $slug = EventTextNormalizer::citySlug($city['label'] ?? $key);

            if ($slug !== null && $slug === $event->city_slug) {
                return $city['timezone'] ?? (string) config('app.timezone', 'UTC');
            }
        }

        $default = (string) config('eventpulse.default_city');

        return (string) config("eventpulse.cities.{$default}.timezone", (string) config('app.timezone', 'UTC'));
    }

    /**
     * How likely it is that two stored events are the same event.
     */
    public function scorePair(Event $a, Event $b): float
    {
        return $this->deduplicator->score($this->asRawEvent($a), $b, $this->timezoneFor($b));
    }

    /**
     * Greedily cluster a same-city, same-day bucket around its best members.
     *
     * @param  Collection<int, Event>  $events
     * @return Collection<int, DuplicateGroup>
     */
    private function clusterBucket(Collection $events, float $minimumScore): Collection
    {
        /** @var Collection<int, DuplicateGroup> $groups */
        $groups = collect();
        /** @var array<string, true> $consumed */
        $consumed = [];

        foreach ($events as $leader) {
            if (isset($consumed[$leader->id])) {
                continue;
            }

            $cluster = collect([$leader]);
            $lowestScore = 1.0;

            foreach ($events as $other) {
                if ($other->id === $leader->id || isset($consumed[$other->id])) {
                    continue;
                }

                $score = $this->scorePair($other, $leader);

                if ($score < $minimumScore) {
                    continue;
                }

                $cluster->push($other);
                $lowestScore = min($lowestScore, $score);
                $consumed[$other->id] = true;
            }

            if ($cluster->count() < 2) {
                continue;
            }

            $consumed[$leader->id] = true;

            $groups->push(new DuplicateGroup(
                key: $leader->id,
                events: $this->rank($cluster),
                reason: 'score',
                score: $lowestScore,
            ));
        }

        return $groups;
    }

    private function compare(Event $a, Event $b): int
    {
        return [
            -$this->merger->sourcePriority($a->source),
            -$this->completeness($a),
            $a->created_at?->getTimestamp() ?? 0,
            $a->id,
        ] <=> [
            -$this->merger->sourcePriority($b->source),
            -$this->completeness($b),
            $b->created_at?->getTimestamp() ?? 0,
            $b->id,
        ];
    }

    private function completeness(Event $event): int
    {
        $fields = ['description', 'venue', 'address', 'image_url', 'price_min', 'latitude', 'ends_at'];

        return count(array_filter($fields, fn (string $field): bool => $event->{$field} !== null));
    }

    /**
     * Represent a stored event as a raw one so the matcher can score it
     * against another stored event using exactly the same rules the pipeline
     * applies to freshly scraped data.
     */
    private function asRawEvent(Event $event): RawEvent
    {
        return new RawEvent(
            title: $event->title,
            description: $event->description,
            sourceUrl: $event->source_url,
            sourceId: $event->source_id,
            source: $event->source,
            venue: $event->venue,
            address: $event->address,
            city: $event->city,
            startsAt: $event->starts_at?->toDateTimeString(),
            endsAt: $event->ends_at?->toDateTimeString(),
            priceMin: $event->price_min,
            priceMax: $event->price_max,
            currency: $event->currency,
            isFree: $event->is_free,
            imageUrl: $event->image_url,
            metadata: $event->metadata ?? [],
        );
    }
}
