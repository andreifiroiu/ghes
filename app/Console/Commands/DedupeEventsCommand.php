<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DTOs\RawEvent;
use App\Models\Event;
use App\Models\EventSource;
use App\Services\Processing\EventDeduplicator;
use App\Services\Processing\EventMerger;
use App\Services\Processing\EventTextNormalizer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Backfills the identity columns introduced with the canonical-event model,
 * then merges duplicates that are already in the database.
 *
 * Phase 0 must run immediately after the migrations: until every event has a
 * match_key and an event_sources row, the pipeline cannot recognise anything
 * imported before the change and would duplicate the whole back catalogue.
 */
class DedupeEventsCommand extends Command
{
    protected $signature = 'eventpulse:dedupe-events
        {--city= : Limit to one configured city key}
        {--since= : Only consider events starting on or after this date}
        {--backfill-only : Run phase 0 (key and source backfill) and stop}
        {--fuzzy : Also run the scored pass over same-day candidates}
        {--min-score= : Override eventpulse.dedup.min_score for this run}
        {--dry-run : Report what would change without writing anything}';

    protected $description = 'Backfill event identity keys and merge duplicate events';

    private bool $dryRun = false;

    public function handle(EventMerger $merger): int
    {
        $this->dryRun = (bool) $this->option('dry-run');

        if ($minScore = $this->option('min-score')) {
            config()->set('eventpulse.dedup.min_score', (float) $minScore);
        }

        // A dry run does the real work inside a transaction and rolls it back.
        // Phases 1 and 2 group on the keys phase 0 writes, so previewing them
        // without writing anything first would always report zero merges.
        if ($this->dryRun) {
            $this->warn('Dry run — every change below will be rolled back.');
            DB::beginTransaction();
        }

        try {
            $backfilled = $this->backfillIdentity();
            $this->info("Phase 0: backfilled {$backfilled} events.");

            if ($this->option('backfill-only')) {
                return self::SUCCESS;
            }

            $merged = $this->mergeByMatchKey($merger);
            $this->info("Phase 1: merged {$merged} duplicates on an exact key match.");

            if ($this->option('fuzzy')) {
                $fuzzyMerged = $this->mergeByScore($merger);
                $this->info("Phase 2: merged {$fuzzyMerged} duplicates on a scored match.");
            }

            return self::SUCCESS;
        } finally {
            if ($this->dryRun) {
                DB::rollBack();
                $this->warn('Dry run complete — nothing was written.');
            }
        }
    }

    /**
     * Phase 0 — give every event its derived identity columns and an
     * event_sources row describing where it originally came from.
     */
    private function backfillIdentity(): int
    {
        $count = 0;

        $this->baseQuery()
            ->where(function ($query): void {
                $query->whereNull('match_key')->orWhereNull('city_slug');
            })
            ->orderBy('id')
            ->chunkById(200, function (Collection $events) use (&$count): void {
                /** @var Collection<int, Event> $events */
                foreach ($events as $event) {
                    $timezone = $this->timezoneFor($event);

                    $localDate = $event->starts_at === null
                        ? null
                        : EventTextNormalizer::localDate($event->starts_at->toDateTimeString(), $timezone);

                    Event::withoutSyncingToSearch(function () use ($event, $localDate): void {
                        $event->forceFill([
                            'city_slug' => EventTextNormalizer::citySlug($event->city),
                            'local_date' => $localDate,
                            'match_key' => EventTextNormalizer::matchKey($event->title, $event->city, $localDate),
                        ])->save();
                    });

                    $this->backfillSourceRow($event, $localDate);

                    $count++;
                }
            });

        return $count;
    }

    /**
     * Recreate the event_sources row for an event imported before the table
     * existed, from the provider details stored on the event itself.
     */
    private function backfillSourceRow(Event $event, ?string $localDate): void
    {
        $occurrenceKey = EventTextNormalizer::occurrenceKey($localDate);
        $urlKey = EventTextNormalizer::normalizeUrl($event->source_url);

        $exists = EventSource::query()
            ->where('source', $event->source)
            ->where('url_key', $urlKey)
            ->where('occurrence_key', $occurrenceKey)
            ->exists();

        if ($exists) {
            return;
        }

        EventSource::create([
            'event_id' => $event->id,
            'source' => $event->source,
            'source_url' => $event->source_url,
            'url_key' => $urlKey,
            'source_id' => $event->source_id,
            'occurrence_key' => $occurrenceKey,
            'title' => $event->title,
            'starts_at' => $event->starts_at,
            'payload' => [],
            'first_seen_at' => $event->created_at ?? now(),
            'last_seen_at' => $event->updated_at ?? now(),
        ]);
    }

    /**
     * Phase 1 — merge events that share a blocking key exactly.
     */
    private function mergeByMatchKey(EventMerger $merger): int
    {
        $duplicateKeys = $this->baseQuery()
            ->canonical()
            ->whereNotNull('match_key')
            ->select('match_key')
            ->groupBy('match_key')
            ->havingRaw('count(*) > 1')
            ->pluck('match_key');

        $merged = 0;

        foreach ($duplicateKeys as $matchKey) {
            $group = $this->baseQuery()
                ->canonical()
                ->where('match_key', $matchKey)
                ->get();

            $merged += $this->mergeGroup($merger, $group);
        }

        return $merged;
    }

    /**
     * Phase 2 — score every same-city, same-day pair that phase 1 left alone.
     */
    private function mergeByScore(EventMerger $merger): int
    {
        $deduplicator = app(EventDeduplicator::class);
        $minimumScore = (float) config('eventpulse.dedup.min_score', 0.75);
        $maxCandidates = (int) config('eventpulse.dedup.max_candidates', 200);

        $buckets = $this->baseQuery()
            ->canonical()
            ->whereNotNull('local_date')
            ->select('city_slug', 'local_date')
            ->groupBy('city_slug', 'local_date')
            ->havingRaw('count(*) > 1')
            ->get();

        $merged = 0;

        foreach ($buckets as $bucket) {
            $events = $this->baseQuery()
                ->canonical()
                ->where('city_slug', $bucket->city_slug)
                ->whereDate('local_date', $bucket->local_date)
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit($maxCandidates)
                ->get();

            $merged += $this->mergeBucket($merger, $deduplicator, $events, $minimumScore);
        }

        return $merged;
    }

    /**
     * @param  Collection<int, Event>  $events
     */
    private function mergeBucket(
        EventMerger $merger,
        EventDeduplicator $deduplicator,
        Collection $events,
        float $minimumScore,
    ): int {
        $merged = 0;
        /** @var array<string, true> $consumed */
        $consumed = [];

        foreach ($events as $candidate) {
            if (isset($consumed[$candidate->id])) {
                continue;
            }

            foreach ($events as $other) {
                if ($other->id === $candidate->id || isset($consumed[$other->id])) {
                    continue;
                }

                $score = $deduplicator->score(
                    $this->asRawEvent($other),
                    $candidate,
                    $this->timezoneFor($candidate),
                );

                if ($score < $minimumScore) {
                    continue;
                }

                [$winner, $loser] = $this->rank($candidate, $other, $merger);

                $this->reportMerge($winner, $loser, $score);

                $merger->mergeInto($winner, $loser, syncSearch: ! $this->dryRun);

                $consumed[$loser->id] = true;
                $merged++;
            }
        }

        return $merged;
    }

    /**
     * @param  Collection<int, Event>  $group
     */
    private function mergeGroup(EventMerger $merger, Collection $group): int
    {
        if ($group->count() < 2) {
            return 0;
        }

        $winner = $group->sort(fn (Event $a, Event $b): int => $this->compare($a, $b, $merger))->first();

        $merged = 0;

        foreach ($group as $event) {
            if ($event->id === $winner->id) {
                continue;
            }

            $this->reportMerge($winner, $event, 1.0);

            $merger->mergeInto($winner, $event, syncSearch: ! $this->dryRun);

            $merged++;
        }

        return $merged;
    }

    /**
     * @return array{0: Event, 1: Event}
     */
    private function rank(Event $a, Event $b, EventMerger $merger): array
    {
        return $this->compare($a, $b, $merger) <= 0 ? [$a, $b] : [$b, $a];
    }

    /**
     * Order two events by how good a canonical they would make: source
     * priority first, then how complete the row is, then age.
     */
    private function compare(Event $a, Event $b, EventMerger $merger): int
    {
        return [
            -$merger->sourcePriority($a->source),
            -$this->completeness($a),
            $a->created_at?->getTimestamp() ?? 0,
            $a->id,
        ] <=> [
            -$merger->sourcePriority($b->source),
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

    private function reportMerge(Event $winner, Event $loser, float $score): void
    {
        $this->line(sprintf(
            '  merge [%.2f] "%s" (%s) <- "%s" (%s)',
            $score,
            $winner->title,
            $winner->source,
            $loser->title,
            $loser->source,
        ));
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

    /**
     * @return Builder<Event>
     */
    private function baseQuery(): Builder
    {
        $query = Event::query();

        if ($city = $this->option('city')) {
            $label = (string) config("eventpulse.cities.{$city}.label", (string) $city);
            $query->where('city_slug', EventTextNormalizer::citySlug($label));
        }

        if ($since = $this->option('since')) {
            $query->where('starts_at', '>=', $since);
        }

        return $query;
    }

    private function timezoneFor(Event $event): string
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
}
