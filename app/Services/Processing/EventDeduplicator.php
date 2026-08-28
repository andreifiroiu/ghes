<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\DTOs\RawEvent;
use App\Models\Event;
use App\Models\EventSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Decides whether a scraped event is one we already have.
 *
 * Three layers, cheapest first:
 *   1. Same source, same URL/id, same occurrence — a re-scrape.
 *   2. A different source whose blocking key matches exactly.
 *   3. A different source scoring above threshold against a same-city,
 *      same-window candidate.
 *
 * Everything is keyed on the *local calendar date in the city's timezone*
 * rather than on an exact timestamp, because providers disagree on the time
 * of day by hours (and some publish only a date).
 */
class EventDeduplicator
{
    /**
     * The blocking key for a raw event.
     */
    public function matchKey(RawEvent $event, string $timezone): string
    {
        return EventTextNormalizer::matchKey(
            $event->title,
            $event->city,
            $this->localDate($event, $timezone),
        );
    }

    /**
     * The local calendar date of a raw event in the city's timezone.
     */
    public function localDate(RawEvent $event, string $timezone): ?string
    {
        return EventTextNormalizer::localDate($event->startsAt, $timezone);
    }

    /**
     * The occurrence key used by the event_sources unique constraint.
     */
    public function occurrenceKey(RawEvent $event, string $timezone): string
    {
        return EventTextNormalizer::occurrenceKey($this->localDate($event, $timezone));
    }

    /**
     * Find this provider's own earlier report of the same occurrence.
     *
     * Prefers the provider's stable external id, falling back to the
     * canonicalised URL when the provider does not expose one.
     */
    public function findExistingSource(RawEvent $event, string $timezone): ?EventSource
    {
        $occurrenceKey = $this->occurrenceKey($event, $timezone);

        if ($event->sourceId !== null && $event->sourceId !== '') {
            $bySourceId = EventSource::query()
                ->where('source', $event->source)
                ->where('source_id', $event->sourceId)
                ->where('occurrence_key', $occurrenceKey)
                ->first();

            if ($bySourceId !== null) {
                return $bySourceId;
            }
        }

        return EventSource::query()
            ->where('source', $event->source)
            ->where('url_key', EventTextNormalizer::normalizeUrl($event->sourceUrl))
            ->where('occurrence_key', $occurrenceKey)
            ->first();
    }

    /**
     * Find a canonical event whose blocking key matches exactly.
     *
     * Titles that reduce to very few significant tokens ("Concert",
     * "Petrecere") are too generic to merge on the key alone — those are
     * pushed to the scored path, where the venue carries real weight.
     */
    public function findByMatchKey(string $matchKey, string $title): ?Event
    {
        $minimumTokens = (int) config('eventpulse.dedup.min_title_tokens_for_key_match', 2);

        if (count(EventTextNormalizer::titleTokens($title)) < $minimumTokens) {
            return null;
        }

        return Event::query()
            ->canonical()
            ->where('match_key', $matchKey)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * Find the best-scoring canonical event that is probably the same event.
     */
    public function findFuzzyDuplicate(RawEvent $event, string $timezone): ?Event
    {
        $minimumScore = (float) config('eventpulse.dedup.min_score', 0.75);
        $best = null;
        $bestScore = 0.0;

        foreach ($this->candidatesFor($event, $timezone) as $candidate) {
            $score = $this->score($event, $candidate, $timezone);

            if ($score >= $minimumScore && $score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * Score how likely it is that a raw event and a stored event are the same.
     *
     * Returns 0.0 when the title similarity alone fails to clear the floor, so
     * that agreement on venue and date can never merge two different acts
     * playing the same club on the same night.
     */
    public function score(RawEvent $event, Event $candidate, string $timezone): float
    {
        $titleScore = $this->titleScore($event->title, $candidate->title);

        if ($titleScore < (float) config('eventpulse.dedup.min_title_similarity', 0.60)) {
            return 0.0;
        }

        /** @var array{title: float, venue: float, time: float} $weights */
        $weights = config('eventpulse.dedup.weights', ['title' => 0.60, 'venue' => 0.25, 'time' => 0.15]);

        $total = (float) $weights['title'] + (float) $weights['venue'] + (float) $weights['time'];

        if ($total <= 0.0) {
            return 0.0;
        }

        $weighted = $titleScore * (float) $weights['title']
            + $this->venueScore($event->venue, $candidate->venue) * (float) $weights['venue']
            + $this->timeScore($event, $candidate, $timezone) * (float) $weights['time'];

        return $weighted / $total;
    }

    /**
     * Candidate events to score: same city, within the configured day window.
     *
     * @return Collection<int, Event>
     */
    private function candidatesFor(RawEvent $event, string $timezone): Collection
    {
        $citySlug = EventTextNormalizer::citySlug($event->city);
        $localDate = $this->localDate($event, $timezone);
        $windowDays = (int) config('eventpulse.dedup.match_window_days', 1);

        $query = Event::query()
            ->canonical()
            ->limit((int) config('eventpulse.dedup.max_candidates', 200))
            ->orderBy('created_at')
            ->orderBy('id');

        if ($citySlug !== null) {
            $query->where('city_slug', $citySlug);
        }

        if ($localDate === null) {
            // An undated event can only sensibly match another undated one.
            return $query->whereNull('local_date')->get();
        }

        $date = CarbonImmutable::parse($localDate);

        // Without a city to narrow on, insist the day matches exactly.
        if ($citySlug === null) {
            return $query->whereDate('local_date', $date->toDateString())->get();
        }

        return $query->whereBetween('local_date', [
            $date->subDays($windowDays)->toDateString(),
            $date->addDays($windowDays)->toDateString(),
        ])->get();
    }

    /**
     * Title similarity in [0, 1].
     *
     * Takes the better of a token-set Jaccard (robust to provider suffixes
     * and word order) and a character similarity over the normalised token
     * key (robust to inflections and small spelling drift).
     */
    private function titleScore(string $a, string $b): float
    {
        $tokensA = EventTextNormalizer::titleTokens($a);
        $tokensB = EventTextNormalizer::titleTokens($b);

        $jaccard = $this->jaccard($tokensA, $tokensB);

        $keyA = EventTextNormalizer::titleKey($a);
        $keyB = EventTextNormalizer::titleKey($b);

        if ($keyA === '' || $keyB === '') {
            return $jaccard;
        }

        similar_text($keyA, $keyB, $percent);

        return max($jaccard, $percent / 100);
    }

    /**
     * Venue similarity in [0, 1], neutral when either side is unknown.
     */
    private function venueScore(?string $a, ?string $b): float
    {
        if ($a === null || $b === null || trim($a) === '' || trim($b) === '') {
            return 0.5;
        }

        $normalisedA = EventTextNormalizer::normalizeTitle($a);
        $normalisedB = EventTextNormalizer::normalizeTitle($b);

        if ($normalisedA === '' || $normalisedB === '') {
            return 0.5;
        }

        if ($normalisedA === $normalisedB) {
            return 1.0;
        }

        // Providers routinely append the city: "Casa Tineretului, Timisoara".
        if (str_contains($normalisedA, $normalisedB) || str_contains($normalisedB, $normalisedA)) {
            return 0.9;
        }

        similar_text($normalisedA, $normalisedB, $percent);

        return $percent / 100;
    }

    /**
     * Time agreement in [0, 1].
     *
     * Only compares clock times when *both* sides have a real one: several
     * adapters publish date-only events as local midnight, and treating that
     * as "00:00" would penalise every correct match against them.
     */
    private function timeScore(RawEvent $event, Event $candidate, string $timezone): float
    {
        $rawDate = $this->localDate($event, $timezone);
        $candidateDate = $candidate->starts_at === null
            ? null
            : EventTextNormalizer::localDate($candidate->starts_at->toDateTimeString(), $timezone);

        if ($rawDate === null || $candidateDate === null) {
            return 0.5;
        }

        $dayScore = $rawDate === $candidateDate ? 1.0 : 0.4;

        $rawTime = $this->localTime($event->startsAt, $timezone);
        $candidateTime = $candidate->starts_at === null
            ? null
            : $this->localTime($candidate->starts_at->toDateTimeString(), $timezone);

        // Midnight means "date only" for most of our adapters — not a real time.
        if ($rawTime === null || $candidateTime === null) {
            return $dayScore * 0.5 + 0.25;
        }

        $hoursApart = abs($rawTime->diffInMinutes($candidateTime)) / 60;

        $proximity = max(0.0, 1.0 - ($hoursApart / 6));

        return ($dayScore + $proximity) / 2;
    }

    /**
     * The local time of a timestamp, or null when it is midnight (which our
     * adapters use to mean "no time known").
     */
    private function localTime(?string $utcDateTime, string $timezone): ?CarbonImmutable
    {
        if ($utcDateTime === null || trim($utcDateTime) === '') {
            return null;
        }

        try {
            $local = CarbonImmutable::parse($utcDateTime)->setTimezone($timezone);
        } catch (Throwable) {
            return null;
        }

        if ($local->hour === 0 && $local->minute === 0) {
            return null;
        }

        return $local;
    }

    /**
     * Jaccard similarity of two token sets.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));

        return $intersection / $union;
    }
}
