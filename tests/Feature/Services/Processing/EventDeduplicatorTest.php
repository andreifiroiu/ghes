<?php

declare(strict_types=1);

use App\DTOs\RawEvent;
use App\Models\Event;
use App\Services\Processing\EventDeduplicator;
use App\Services\Processing\EventTextNormalizer;

/**
 * Scoring behaviour of the matcher: how the title, venue and time components
 * combine, and how the configured thresholds gate a merge.
 *
 * The blocking-key and candidate-selection behaviour is covered by
 * tests/Feature/Processing/EventDeduplicatorTest.php.
 */
beforeEach(function () {
    $this->deduplicator = new EventDeduplicator;
    $this->timezone = 'Europe/Bucharest';
});

/**
 * @param  array<string, mixed>  $overrides
 */
function scoringRawEvent(array $overrides = []): RawEvent
{
    return new RawEvent(
        title: $overrides['title'] ?? 'Concert Phoenix',
        description: null,
        sourceUrl: $overrides['source_url'] ?? 'https://allevents.in/timisoara/concert-phoenix',
        sourceId: null,
        source: $overrides['source'] ?? 'allevents',
        venue: array_key_exists('venue', $overrides) ? $overrides['venue'] : 'Casa Tineretului',
        address: null,
        city: 'Timișoara',
        startsAt: array_key_exists('starts_at', $overrides) ? $overrides['starts_at'] : '2026-05-10 17:00:00',
        endsAt: null,
        priceMin: null,
        priceMax: null,
        currency: 'RON',
        isFree: false,
        imageUrl: null,
        metadata: [],
    );
}

/**
 * @param  array<string, mixed>  $overrides
 */
function scoringEvent(array $overrides = []): Event
{
    $title = $overrides['title'] ?? 'Concert Phoenix';
    $startsAt = array_key_exists('starts_at', $overrides) ? $overrides['starts_at'] : '2026-05-10 17:00:00';
    $localDate = EventTextNormalizer::localDate($startsAt, 'Europe/Bucharest');

    return Event::factory()->create([
        'title' => $title,
        'city' => 'Timișoara',
        'venue' => array_key_exists('venue', $overrides) ? $overrides['venue'] : 'Casa Tineretului',
        'starts_at' => $startsAt,
        'local_date' => $localDate,
        'source' => $overrides['source'] ?? 'iabilet',
    ]);
}

it('scores an identical listing from another provider at the top of the range', function () {
    $score = $this->deduplicator->score(scoringRawEvent(), scoringEvent(), $this->timezone);

    expect($score)->toBeGreaterThan(0.9);
});

it('scores a title-only difference below the merge threshold', function () {
    $score = $this->deduplicator->score(
        scoringRawEvent(['title' => 'Stand-up Comedy cu Micutzu']),
        scoringEvent(['title' => 'Concert Phoenix']),
        $this->timezone,
    );

    expect($score)->toBe(0.0);
});

it('returns zero when the title floor is not cleared, however well venue and time agree', function () {
    // Same venue, same minute — only the act differs.
    $score = $this->deduplicator->score(
        scoringRawEvent(['title' => 'Concert Byron']),
        scoringEvent(['title' => 'Recital Maria Tanase']),
        $this->timezone,
    );

    expect($score)->toBe(0.0);
});

it('still clears the threshold when the venue is unknown on one side', function () {
    $score = $this->deduplicator->score(
        scoringRawEvent(['venue' => null]),
        scoringEvent(['venue' => 'Casa Tineretului']),
        $this->timezone,
    );

    expect($score)->toBeGreaterThanOrEqual((float) config('eventpulse.dedup.min_score'));
});

it('treats a venue that merely appends the city as the same venue', function () {
    $score = $this->deduplicator->score(
        scoringRawEvent(['venue' => 'Casa Tineretului, Timisoara']),
        scoringEvent(['venue' => 'Casa Tineretului']),
        $this->timezone,
    );

    expect($score)->toBeGreaterThan(0.9);
});

it('does not penalise a date-only listing against a timed one', function () {
    $withTime = $this->deduplicator->score(
        scoringRawEvent(['starts_at' => '2026-05-10 17:00:00']),
        scoringEvent(['starts_at' => '2026-05-10 17:00:00']),
        $this->timezone,
    );

    $dateOnly = $this->deduplicator->score(
        scoringRawEvent(['starts_at' => '2026-05-09 21:00:00']), // local midnight on the 10th
        scoringEvent(['starts_at' => '2026-05-10 17:00:00']),
        $this->timezone,
    );

    expect($dateOnly)->toBeGreaterThanOrEqual((float) config('eventpulse.dedup.min_score'))
        ->and($dateOnly)->toBeLessThanOrEqual($withTime);
});

it('honours a raised minimum score from config', function () {
    config()->set('eventpulse.dedup.min_score', 0.99);

    $raw = scoringRawEvent(['title' => 'Concert Phoenix la Timisoara', 'venue' => null]);
    scoringEvent(['title' => 'Concert Phoenix']);

    expect($this->deduplicator->findFuzzyDuplicate($raw, $this->timezone))->toBeNull();
});

it('honours a lowered title floor from config', function () {
    config()->set('eventpulse.dedup.min_title_similarity', 0.0);
    config()->set('eventpulse.dedup.min_score', 0.0);

    $score = $this->deduplicator->score(
        scoringRawEvent(['title' => 'Concert Byron']),
        scoringEvent(['title' => 'Recital Maria Tanase']),
        $this->timezone,
    );

    expect($score)->toBeGreaterThan(0.0);
});
