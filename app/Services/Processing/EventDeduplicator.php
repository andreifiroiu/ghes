<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\DTOs\RawEvent;
use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class EventDeduplicator
{
    /**
     * Generate a deterministic fingerprint from a raw event.
     *
     * Combines normalised title, source URL, and start time, then hashes.
     */
    public function generateFingerprint(RawEvent $event): string
    {
        $title = mb_strtolower(trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $event->title)));
        $url = mb_strtolower(rtrim(strtok($event->sourceUrl, '?'), '/'));

        $startsAt = $event->startsAt
            ? Carbon::parse($event->startsAt)->format('Y-m-d H:i')
            : '';

        return hash('sha256', "{$title}|{$url}|{$startsAt}");
    }

    /**
     * Check if an event with this fingerprint already exists.
     */
    public function isDuplicate(string $fingerprint): bool
    {
        return Event::where('fingerprint', $fingerprint)->exists();
    }

    /**
     * Fuzzy duplicate: look for events with a very similar title within
     * a ±2-hour window of the same start time.
     */
    public function findFuzzyDuplicates(RawEvent $event): ?Event
    {
        if (! $event->startsAt) {
            return null;
        }

        $startsAt = Carbon::parse($event->startsAt);
        $normalisedTitle = mb_strtolower(trim($event->title));

        $candidates = Event::query()
            ->whereBetween('starts_at', [
                $startsAt->copy()->subHours(2),
                $startsAt->copy()->addHours(2),
            ])
            ->when($event->city !== null, fn ($q) => $q->where('city', $event->city))
            ->get();

        foreach ($candidates as $candidate) {
            $candidateTitle = mb_strtolower(trim($candidate->title));
            similar_text($normalisedTitle, $candidateTitle, $percent);

            if ($percent >= 80.0) {
                Log::debug('EventDeduplicator: fuzzy match found', [
                    'incoming_title' => $event->title,
                    'matched_title' => $candidate->title,
                    'matched_event_id' => $candidate->id,
                    'similarity' => round($percent, 1),
                ]);

                return $candidate;
            }
        }

        return null;
    }
}
