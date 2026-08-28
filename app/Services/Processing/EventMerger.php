<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\DTOs\RawEvent;
use App\Jobs\ClassifyEventJob;
use App\Jobs\DownloadEventImageJob;
use App\Models\DiscoveryLog;
use App\Models\Event;
use App\Models\EventSource;
use App\Models\UserEventReaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Folds a second provider's report into an existing canonical event, and
 * folds one canonical event into another.
 */
class EventMerger
{
    /**
     * Fields taken from an incoming report only when the canonical event has
     * nothing for them.
     *
     * @var array<string, string>
     */
    private const FILL_IF_NULL = [
        'description' => 'description',
        'venue' => 'venue',
        'address' => 'address',
        'city' => 'city',
        'ends_at' => 'endsAt',
        'price_min' => 'priceMin',
        'price_max' => 'priceMax',
        'image_url' => 'imageUrl',
    ];

    /**
     * Record (or refresh) one provider's report of an event.
     */
    public function attachSource(Event $event, RawEvent $raw, string $timezone): EventSource
    {
        $localDate = EventTextNormalizer::localDate($raw->startsAt, $timezone);

        $source = EventSource::firstOrNew([
            'source' => $raw->source,
            'url_key' => EventTextNormalizer::normalizeUrl($raw->sourceUrl),
            'occurrence_key' => EventTextNormalizer::occurrenceKey($localDate),
        ]);

        $source->fill([
            'event_id' => $event->id,
            'source_url' => $raw->sourceUrl,
            'source_id' => $raw->sourceId,
            'title' => $raw->title,
            'starts_at' => $raw->startsAt,
            'payload' => $this->payloadFor($raw),
        ]);

        // Set once, at creation. A re-scrape must not reset it, or the column
        // becomes a second copy of last_seen_at and the backfilled history
        // seeded from events.created_at is lost on the next scrape.
        $source->first_seen_at ??= now();
        $source->last_seen_at = now();

        $source->save();

        return $source;
    }

    /**
     * Fold an incoming report into a canonical event.
     *
     * @param  bool  $authoritative  True when the report comes from the same
     *                               provider that produced the stored values, in
     *                               which case it may overwrite them outright.
     */
    public function enrich(Event $event, RawEvent $raw, string $timezone, bool $authoritative = false): Event
    {
        $before = [
            'title' => $event->title,
            'city' => $event->city,
            'starts_at' => $event->starts_at?->toDateTimeString(),
            'image_url' => $event->image_url,
        ];

        $attributes = [];

        foreach (self::FILL_IF_NULL as $column => $property) {
            $incoming = $raw->{$property};

            if ($incoming === null || $incoming === '') {
                continue;
            }

            if ($authoritative || $event->{$column} === null || $event->{$column} === '') {
                $attributes[$column] = $incoming;
            }
        }

        // A stored `false` is indistinguishable from "unknown", so only ever
        // promote a positive free-entry claim, and never over real price data.
        if ($raw->isFree === true && $event->price_min === null && $event->price_max === null) {
            $attributes['is_free'] = true;
        }

        if ($raw->currency !== null && $authoritative) {
            $attributes['currency'] = $raw->currency;
        }

        $attributes += $this->startsAtAttributes($event, $raw, $timezone, $authoritative);

        if ($authoritative || $this->outranksCurrent($raw->source, $event->source)) {
            $attributes['title'] = $raw->title;
            $attributes['source'] = $raw->source;
            $attributes['source_url'] = $raw->sourceUrl;
            $attributes['source_id'] = $raw->sourceId;

            if ($raw->imageUrl !== null && $raw->imageUrl !== '') {
                $attributes['image_url'] = $raw->imageUrl;
            }
        }

        if ($raw->metadata !== []) {
            $metadata = $event->metadata ?? [];
            $metadata['sources'] = $metadata['sources'] ?? [];
            $metadata['sources'][$raw->source] = $raw->metadata;
            $attributes['metadata'] = $metadata;
        }

        $attributes['last_seen_at'] = now();

        Event::withoutSyncingToSearch(function () use ($event, $attributes): void {
            $event->forceFill($attributes)->save();
        });

        if ($this->identityChanged($event, $before)) {
            $this->recomputeKeys($event, $timezone);
        }

        $this->dispatchFollowUpJobs($event, $before);

        return $event;
    }

    /**
     * Recompute the derived identity columns after a title/city/date change.
     */
    public function recomputeKeys(Event $event, string $timezone): void
    {
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
    }

    /**
     * Fold one canonical event into another, moving everything that points at
     * the duplicate across and marking it merged.
     *
     * The duplicate row is kept, not deleted: reactions and discovery logs
     * cascade on delete, and already-sent digests reference event ids in a
     * JSON column with no foreign key.
     */
    public function mergeInto(Event $canonical, Event $duplicate, bool $syncSearch = true): Event
    {
        if ($canonical->id === $duplicate->id) {
            return $canonical;
        }

        DB::transaction(function () use ($canonical, $duplicate, $syncSearch): void {
            EventSource::where('event_id', $duplicate->id)->update(['event_id' => $canonical->id]);
            DiscoveryLog::where('event_id', $duplicate->id)->update(['event_id' => $canonical->id]);

            $this->moveReactions($canonical, $duplicate);

            Event::withoutSyncingToSearch(function () use ($canonical, $duplicate): void {
                $duplicate->forceFill(['merged_into_id' => $canonical->id])->save();

                $canonical->forceFill([
                    'sources_count' => $canonical->sources()->count(),
                ])->save();
            });

            if ($syncSearch) {
                // Never let an offline Meilisearch abort a merge that has
                // already moved reactions and sources across.
                try {
                    $duplicate->unsearchable();
                } catch (Throwable $e) {
                    Log::warning('EventMerger: could not remove merged event from the search index', [
                        'duplicate_id' => $duplicate->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        Log::info('EventMerger: merged duplicate event', [
            'canonical_id' => $canonical->id,
            'canonical_title' => $canonical->title,
            'duplicate_id' => $duplicate->id,
            'duplicate_title' => $duplicate->title,
        ]);

        return $canonical;
    }

    /**
     * Refresh the denormalised source counter.
     */
    public function recountSources(Event $event): void
    {
        Event::withoutSyncingToSearch(function () use ($event): void {
            $event->forceFill(['sources_count' => max(1, $event->sources()->count())])->save();
        });
    }

    /**
     * Where a source ranks when two providers disagree about a field.
     */
    public function sourcePriority(string $source): int
    {
        /** @var array<string, int> $priorities */
        $priorities = config('eventpulse.dedup.source_priority', []);

        return $priorities[$source] ?? (int) config('eventpulse.dedup.default_source_priority', 10);
    }

    /**
     * Move a duplicate's reactions onto the canonical event.
     *
     * user_event_reactions is unique on (user_id, event_id), so when the user
     * already reacted to the canonical event the duplicate's row is dropped.
     * It is never re-processed: its feedback delta was already applied to the
     * user's interest profile, and replaying it would double-count.
     */
    private function moveReactions(Event $canonical, Event $duplicate): void
    {
        foreach ($duplicate->reactions()->cursor() as $reaction) {
            $alreadyReacted = UserEventReaction::query()
                ->where('user_id', $reaction->user_id)
                ->where('event_id', $canonical->id)
                ->exists();

            if ($alreadyReacted) {
                $reaction->delete();

                continue;
            }

            $reaction->forceFill(['event_id' => $canonical->id])->save();
        }
    }

    /**
     * Decide what to do with an incoming start time.
     *
     * The valuable case is upgrading a date-only row (stored as local
     * midnight) with a real time from another provider for the same day.
     *
     * @return array<string, mixed>
     */
    private function startsAtAttributes(Event $event, RawEvent $raw, string $timezone, bool $authoritative): array
    {
        if ($raw->startsAt === null) {
            return [];
        }

        if ($authoritative || $event->starts_at === null) {
            return ['starts_at' => $raw->startsAt];
        }

        $storedLocal = EventTextNormalizer::localDate($event->starts_at->toDateTimeString(), $timezone);
        $incomingLocal = EventTextNormalizer::localDate($raw->startsAt, $timezone);

        if ($storedLocal === null || $storedLocal !== $incomingLocal) {
            return [];
        }

        $storedIsDateOnly = $event->starts_at->copy()->setTimezone($timezone)->format('H:i') === '00:00';
        $incomingHasTime = CarbonImmutable::parse($raw->startsAt)->setTimezone($timezone)->format('H:i') !== '00:00';

        return $storedIsDateOnly && $incomingHasTime ? ['starts_at' => $raw->startsAt] : [];
    }

    /**
     * Whether the incoming source outranks the one that currently owns the
     * canonical event's headline fields.
     */
    private function outranksCurrent(string $incoming, ?string $current): bool
    {
        if ($current === null) {
            return true;
        }

        return $this->sourcePriority($incoming) > $this->sourcePriority($current);
    }

    /**
     * @param  array<string, mixed>  $before
     */
    private function identityChanged(Event $event, array $before): bool
    {
        return $event->title !== $before['title']
            || $event->city !== $before['city']
            || $event->starts_at?->toDateTimeString() !== $before['starts_at'];
    }

    /**
     * @param  array<string, mixed>  $before
     */
    private function dispatchFollowUpJobs(Event $event, array $before): void
    {
        if (! $event->is_classified && $event->title !== $before['title']) {
            ClassifyEventJob::dispatch($event->id);
        }

        if ($before['image_url'] === null && $event->image_url !== null) {
            DownloadEventImageJob::dispatch($event);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(RawEvent $raw): array
    {
        return [
            'title' => $raw->title,
            'description' => $raw->description,
            'venue' => $raw->venue,
            'address' => $raw->address,
            'city' => $raw->city,
            'starts_at' => $raw->startsAt,
            'ends_at' => $raw->endsAt,
            'price_min' => $raw->priceMin,
            'price_max' => $raw->priceMax,
            'currency' => $raw->currency,
            'is_free' => $raw->isFree,
            'image_url' => $raw->imageUrl,
            'metadata' => $raw->metadata,
        ];
    }
}
