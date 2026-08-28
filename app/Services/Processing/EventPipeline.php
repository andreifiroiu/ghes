<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\DTOs\RawEvent;
use App\Enums\EventCategory;
use App\Jobs\ClassifyEventJob;
use App\Jobs\DownloadEventImageJob;
use App\Models\Event;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class EventPipeline
{
    public function __construct(
        private readonly EventDeduplicator $deduplicator,
        private readonly EventMerger $merger,
    ) {}

    /**
     * Process a single raw event.
     *
     * Returns the canonical Event it belongs to, whether that event was
     * created now or already existed. Callers can tell the two apart with
     * `wasRecentlyCreated`.
     */
    public function process(RawEvent $rawEvent, ?string $cityKey = null): ?Event
    {
        $timezone = $this->timezoneFor($cityKey);

        try {
            return $this->attempt($rawEvent, $timezone);
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            // Another worker inserted the same event between our lookup and
            // our insert. Retrying now takes the same-source branch and
            // enriches the winner instead of inserting a second row.
            Log::debug('EventPipeline: unique violation, retrying once', [
                'title' => $rawEvent->title,
                'source' => $rawEvent->source,
            ]);

            return $this->attempt($rawEvent, $timezone);
        }
    }

    /**
     * Process a batch of raw events. Failures for individual events are logged
     * but do not halt the batch.
     *
     * @param  Collection<int, RawEvent>  $rawEvents
     * @return Collection<int, Event>
     */
    public function processBatch(Collection $rawEvents, ?string $cityKey = null): Collection
    {
        $results = collect();
        $total = $rawEvents->count();

        foreach ($rawEvents as $rawEvent) {
            try {
                $event = $this->process($rawEvent, $cityKey);
                if ($event !== null) {
                    $results->push($event);
                }
            } catch (Throwable $e) {
                Log::error('EventPipeline: failed to process event', [
                    'title' => $rawEvent->title,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info("EventPipeline: batch complete — {$results->count()}/{$total} events saved");

        return $results;
    }

    /**
     * One matching-and-persisting pass, serialised per blocking key.
     */
    private function attempt(RawEvent $rawEvent, string $timezone): ?Event
    {
        $matchKey = $this->deduplicator->matchKey($rawEvent, $timezone);

        $lock = Cache::lock(
            'eventpulse:match:'.md5($matchKey),
            (int) config('eventpulse.dedup.lock_seconds', 10),
        );

        return $lock->block(
            (int) config('eventpulse.dedup.lock_wait_seconds', 5),
            fn (): Event => DB::transaction(
                fn (): Event => $this->resolve($rawEvent, $timezone, $matchKey)
            ),
        );
    }

    private function resolve(RawEvent $rawEvent, string $timezone, string $matchKey): Event
    {
        // 1. Have we already seen this exact listing from this provider?
        $existingSource = $this->deduplicator->findExistingSource($rawEvent, $timezone);

        if ($existingSource !== null) {
            $canonical = $this->canonicalFor($existingSource->event);

            if ($canonical !== null) {
                $this->merger->enrich($canonical, $rawEvent, $timezone, authoritative: true);
                $this->merger->attachSource($canonical, $rawEvent, $timezone);

                Log::debug('EventPipeline: refreshed existing event', [
                    'title' => $canonical->title,
                    'source' => $rawEvent->source,
                ]);

                return $canonical;
            }
        }

        // 2. Exact blocking-key match from a different provider.
        $canonical = $this->deduplicator->findByMatchKey($matchKey, $rawEvent->title);

        // 3. Scored fallback for titles the blocking key cannot line up.
        if ($canonical === null && (bool) config('eventpulse.dedup.enabled', true)) {
            $canonical = $this->deduplicator->findFuzzyDuplicate($rawEvent, $timezone);
        }

        if ($canonical !== null) {
            $this->merger->enrich($canonical, $rawEvent, $timezone);
            $this->merger->attachSource($canonical, $rawEvent, $timezone);
            $this->merger->recountSources($canonical);

            Log::info('EventPipeline: merged event into existing canonical', [
                'canonical_id' => $canonical->id,
                'canonical_title' => $canonical->title,
                'incoming_title' => $rawEvent->title,
                'source' => $rawEvent->source,
            ]);

            return $canonical;
        }

        return $this->createCanonical($rawEvent, $timezone, $matchKey);
    }

    private function createCanonical(RawEvent $rawEvent, string $timezone, string $matchKey): Event
    {
        $localDate = $this->deduplicator->localDate($rawEvent, $timezone);

        $attributes = [
            'title' => $rawEvent->title,
            'description' => $rawEvent->description,
            'source' => $rawEvent->source,
            'source_url' => $rawEvent->sourceUrl,
            'source_id' => $rawEvent->sourceId,
            'match_key' => $matchKey,
            'category' => EventCategory::Other,
            'tags' => [],
            'venue' => $rawEvent->venue,
            'address' => $rawEvent->address,
            'city' => $rawEvent->city,
            'city_slug' => EventTextNormalizer::citySlug($rawEvent->city),
            'starts_at' => $rawEvent->startsAt,
            'local_date' => $localDate,
            'ends_at' => $rawEvent->endsAt,
            'price_min' => $rawEvent->priceMin,
            'price_max' => $rawEvent->priceMax,
            'currency' => $rawEvent->currency ?? 'RON',
            'is_free' => $rawEvent->isFree ?? false,
            'image_url' => $rawEvent->imageUrl,
            'metadata' => $rawEvent->metadata,
            'sources_count' => 1,
            'last_seen_at' => now(),
            'is_classified' => false,
            'is_geocoded' => false,
            'is_enriched' => false,
        ];

        // Wrap in withoutSyncingToSearch so a missing/offline Meilisearch instance
        // never blocks saving. Scout import can populate the index in a separate step.
        /** @var Event $event */
        $event = Event::withoutSyncingToSearch(fn () => Event::create($attributes));

        $this->merger->attachSource($event, $rawEvent, $timezone);

        Log::info('EventPipeline: saved event', [
            'title' => $event->title,
            'source' => $event->source,
            'starts_at' => $event->getRawOriginal('starts_at'),
        ]);

        ClassifyEventJob::dispatch($event->id);

        if ($rawEvent->imageUrl !== null) {
            DownloadEventImageJob::dispatch($event);
        }

        return $event;
    }

    /**
     * Follow a merged event to the canonical one it now lives under.
     */
    private function canonicalFor(?Event $event): ?Event
    {
        $seen = 0;

        while ($event !== null && $event->merged_into_id !== null && $seen < 5) {
            $event = $event->canonicalEvent;
            $seen++;
        }

        return $event;
    }

    private function timezoneFor(?string $cityKey): string
    {
        $cityKey ??= (string) config('eventpulse.default_city');

        return (string) config(
            "eventpulse.cities.{$cityKey}.timezone",
            (string) config('app.timezone', 'UTC'),
        );
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === '23505'
            || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }
}
