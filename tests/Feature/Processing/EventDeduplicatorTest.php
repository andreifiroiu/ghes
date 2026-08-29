<?php

declare(strict_types=1);

use App\DTOs\RawEvent;
use App\Enums\EventCategory;
use App\Models\Event;
use App\Services\Processing\EventDeduplicator;
use App\Services\Processing\EventTextNormalizer;

const TEST_TIMEZONE = 'Europe/Bucharest';

// ---------------------------------------------------------------------------
// Fixture helpers — insert a minimal Event row directly (bypasses EventPipeline)
// ---------------------------------------------------------------------------

/**
 * @param  array<string, mixed>  $overrides
 */
function dedupEvent(array $overrides = []): Event
{
    $title = $overrides['title'] ?? 'Concert Phoenix';
    $city = $overrides['city'] ?? 'Timișoara';
    $startsAt = array_key_exists('starts_at', $overrides)
        ? $overrides['starts_at']
        : '2026-05-10 19:00:00';

    $localDate = EventTextNormalizer::localDate($startsAt, TEST_TIMEZONE);

    return Event::withoutSyncingToSearch(fn () => Event::create([
        'title' => $title,
        'source' => $overrides['source'] ?? 'iabilet',
        'source_url' => $overrides['source_url'] ?? 'https://iabilet.ro/concert-phoenix/',
        'category' => EventCategory::Other,
        'tags' => [],
        'venue' => $overrides['venue'] ?? null,
        'city' => $city,
        'city_slug' => EventTextNormalizer::citySlug($city),
        'starts_at' => $startsAt,
        'local_date' => $localDate,
        'match_key' => EventTextNormalizer::matchKey($title, $city, $localDate),
        'currency' => 'RON',
        'is_free' => false,
        'is_classified' => false,
        'is_geocoded' => false,
        'is_enriched' => false,
    ]));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function dedupRawEvent(array $overrides = []): RawEvent
{
    return new RawEvent(
        title: $overrides['title'] ?? 'Concert Phoenix',
        description: $overrides['description'] ?? null,
        sourceUrl: $overrides['source_url'] ?? 'https://zilesinopti.ro/evenimente/concert-phoenix/',
        sourceId: $overrides['source_id'] ?? null,
        source: $overrides['source'] ?? 'zilesinopti',
        venue: $overrides['venue'] ?? null,
        address: null,
        city: array_key_exists('city', $overrides) ? $overrides['city'] : 'Timișoara',
        startsAt: array_key_exists('starts_at', $overrides) ? $overrides['starts_at'] : '2026-05-10 19:00:00',
        endsAt: null,
        priceMin: null,
        priceMax: null,
        currency: 'RON',
        isFree: false,
        imageUrl: null,
        metadata: [],
    );
}

beforeEach(function () {
    $this->deduplicator = new EventDeduplicator;
});

// ---------------------------------------------------------------------------
// matchKey — the blocking key
// ---------------------------------------------------------------------------

describe('matchKey', function () {
    it('is identical for the same concert reported by four adapters that disagree on the time', function () {
        // One 20:00 (Europe/Bucharest) concert on 2026-05-10, as each adapter
        // actually stores it today:
        //   iabilet      — date only, local midnight
        //   zilesinopti  — Romanian wall clock written as if it were UTC
        //   allevents    — a correct epoch (17:00 UTC == 20:00 local, EEST)
        //   entertix     — date only forced to local midnight, then ->utc()
        $keys = collect([
            ['source' => 'iabilet', 'starts_at' => '2026-05-10 00:00:00'],
            ['source' => 'zilesinopti', 'starts_at' => '2026-05-10 20:00:00'],
            ['source' => 'allevents', 'starts_at' => '2026-05-10 17:00:00'],
            ['source' => 'entertix', 'starts_at' => '2026-05-09 21:00:00'],
        ])->map(fn (array $o) => $this->deduplicator->matchKey(
            dedupRawEvent($o + ['title' => 'Concert Phoenix']),
            TEST_TIMEZONE,
        ));

        expect($keys->unique())->toHaveCount(1);
    });

    it('ignores provider-specific title decoration', function () {
        $plain = $this->deduplicator->matchKey(dedupRawEvent(['title' => 'Concert Phoenix']), TEST_TIMEZONE);
        $suffixed = $this->deduplicator->matchKey(dedupRawEvent(['title' => 'Concert Phoenix - Live in Timisoara']), TEST_TIMEZONE);
        $prefixed = $this->deduplicator->matchKey(dedupRawEvent(['title' => 'Timisoara: Concert Phoenix']), TEST_TIMEZONE);

        expect($suffixed)->toBe($plain)
            ->and($prefixed)->toBe($plain);
    });

    it('treats diacritic and plain spellings of a city as the same', function () {
        $withDiacritics = $this->deduplicator->matchKey(dedupRawEvent(['city' => 'Timișoara']), TEST_TIMEZONE);
        $without = $this->deduplicator->matchKey(dedupRawEvent(['city' => 'Timisoara']), TEST_TIMEZONE);

        expect($withDiacritics)->toBe($without);
    });

    it('separates different cities', function () {
        $timisoara = $this->deduplicator->matchKey(dedupRawEvent(['city' => 'Timișoara']), TEST_TIMEZONE);
        $cluj = $this->deduplicator->matchKey(dedupRawEvent(['city' => 'Cluj-Napoca']), TEST_TIMEZONE);

        expect($timisoara)->not->toBe($cluj);
    });

    it('separates different days', function () {
        $first = $this->deduplicator->matchKey(dedupRawEvent(['starts_at' => '2026-05-10 19:00:00']), TEST_TIMEZONE);
        $second = $this->deduplicator->matchKey(dedupRawEvent(['starts_at' => '2026-05-11 19:00:00']), TEST_TIMEZONE);

        expect($first)->not->toBe($second);
    });

    it('separates different events on the same day', function () {
        $phoenix = $this->deduplicator->matchKey(dedupRawEvent(['title' => 'Concert Phoenix']), TEST_TIMEZONE);
        $subcarpati = $this->deduplicator->matchKey(dedupRawEvent(['title' => 'Concert Subcarpati']), TEST_TIMEZONE);

        expect($phoenix)->not->toBe($subcarpati);
    });
});

// ---------------------------------------------------------------------------
// occurrenceKey
// ---------------------------------------------------------------------------

describe('occurrenceKey', function () {
    it('is the local calendar date', function () {
        $key = $this->deduplicator->occurrenceKey(
            dedupRawEvent(['starts_at' => '2026-05-10 17:00:00']),
            TEST_TIMEZONE,
        );

        expect($key)->toBe('2026-05-10');
    });

    it('falls back to a sentinel for undated events', function () {
        $key = $this->deduplicator->occurrenceKey(dedupRawEvent(['starts_at' => null]), TEST_TIMEZONE);

        expect($key)->toBe('undated');
    });
});

// ---------------------------------------------------------------------------
// findByMatchKey
// ---------------------------------------------------------------------------

describe('findByMatchKey', function () {
    it('finds an event stored by another provider', function () {
        $stored = dedupEvent(['title' => 'Concert Phoenix', 'starts_at' => '2026-05-10 00:00:00']);

        $raw = dedupRawEvent(['title' => 'Concert Phoenix - Live in Timisoara', 'starts_at' => '2026-05-10 20:00:00']);
        $key = $this->deduplicator->matchKey($raw, TEST_TIMEZONE);

        expect($this->deduplicator->findByMatchKey($key, $raw->title)?->id)->toBe($stored->id);
    });

    it('ignores events that were merged away', function () {
        $canonical = dedupEvent(['title' => 'Concert Phoenix']);
        $duplicate = dedupEvent(['title' => 'Concert Phoenix', 'source_url' => 'https://other.ro/x/']);
        $duplicate->forceFill(['merged_into_id' => $canonical->id])->save();

        $raw = dedupRawEvent(['title' => 'Concert Phoenix']);
        $found = $this->deduplicator->findByMatchKey($this->deduplicator->matchKey($raw, TEST_TIMEZONE), $raw->title);

        expect($found?->id)->toBe($canonical->id);
    });

    it('refuses to match on a title too generic to identify an event', function () {
        dedupEvent(['title' => 'Concert']);

        $raw = dedupRawEvent(['title' => 'Concert']);

        expect($this->deduplicator->findByMatchKey($this->deduplicator->matchKey($raw, TEST_TIMEZONE), $raw->title))
            ->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// findFuzzyDuplicate
// ---------------------------------------------------------------------------

describe('findFuzzyDuplicate', function () {
    it('matches a date-only listing against a timed one for the same day', function () {
        $stored = dedupEvent([
            'title' => 'Trupa Phoenix in concert',
            'starts_at' => '2026-05-10 00:00:00',
            'venue' => 'Casa Tineretului',
        ]);

        $raw = dedupRawEvent([
            'title' => 'Trupa Phoenix in concert la Timisoara',
            'starts_at' => '2026-05-10 20:00:00',
            'venue' => 'Casa Tineretului, Timisoara',
        ]);

        expect($this->deduplicator->findFuzzyDuplicate($raw, TEST_TIMEZONE)?->id)->toBe($stored->id);
    });

    it('matches across a three-hour timezone disagreement', function () {
        $stored = dedupEvent(['title' => 'Recital Maria Tanase', 'starts_at' => '2026-05-10 17:00:00']);

        $raw = dedupRawEvent(['title' => 'Recital Maria Tanase', 'starts_at' => '2026-05-10 20:00:00']);

        expect($this->deduplicator->findFuzzyDuplicate($raw, TEST_TIMEZONE)?->id)->toBe($stored->id);
    });

    it('does not merge two different acts at the same venue on the same night', function () {
        dedupEvent([
            'title' => 'Concert Subcarpati',
            'starts_at' => '2026-05-10 19:00:00',
            'venue' => 'Casa Tineretului',
        ]);

        $raw = dedupRawEvent([
            'title' => 'Concert Byron',
            'starts_at' => '2026-05-10 19:00:00',
            'venue' => 'Casa Tineretului',
        ]);

        expect($this->deduplicator->findFuzzyDuplicate($raw, TEST_TIMEZONE))->toBeNull();
    });

    it('does not match across cities', function () {
        dedupEvent(['title' => 'Concert Phoenix', 'city' => 'Timișoara']);

        $raw = dedupRawEvent(['title' => 'Concert Phoenix', 'city' => 'Cluj-Napoca']);

        expect($this->deduplicator->findFuzzyDuplicate($raw, TEST_TIMEZONE))->toBeNull();
    });

    it('matches undated events only against other undated events', function () {
        dedupEvent(['title' => 'Expozitie permanenta Brukenthal', 'starts_at' => '2026-05-10 19:00:00']);

        $raw = dedupRawEvent(['title' => 'Expozitie permanenta Brukenthal', 'starts_at' => null]);

        expect($this->deduplicator->findFuzzyDuplicate($raw, TEST_TIMEZONE))->toBeNull();

        $undated = dedupEvent([
            'title' => 'Expozitie permanenta Brukenthal',
            'starts_at' => null,
            'source_url' => 'https://iabilet.ro/expozitie/',
        ]);

        expect($this->deduplicator->findFuzzyDuplicate($raw, TEST_TIMEZONE)?->id)->toBe($undated->id);
    });
});
