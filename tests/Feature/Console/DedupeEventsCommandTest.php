<?php

declare(strict_types=1);

use App\Enums\EventCategory;
use App\Models\Event;
use App\Models\EventSource;

/**
 * Insert an event the way it looked before the canonical-event model existed:
 * no match_key, no city_slug, no local_date, no event_sources row.
 *
 * @param  array<string, mixed>  $overrides
 */
function legacyEvent(array $overrides = []): Event
{
    /** @var Event $event */
    $event = Event::withoutSyncingToSearch(fn () => Event::create([
        'title' => $overrides['title'] ?? 'Concert Phoenix',
        'source' => $overrides['source'] ?? 'zilesinopti',
        'source_url' => $overrides['source_url'] ?? 'https://zilesinopti.ro/evenimente/concert-phoenix/',
        'source_id' => $overrides['source_id'] ?? null,
        'category' => EventCategory::Other,
        'tags' => [],
        'venue' => $overrides['venue'] ?? 'Casa Tineretului',
        'description' => $overrides['description'] ?? null,
        'city' => $overrides['city'] ?? 'Timișoara',
        'starts_at' => $overrides['starts_at'] ?? '2026-05-10 17:00:00',
        'currency' => 'RON',
        'is_free' => false,
        'is_classified' => false,
        'is_geocoded' => false,
        'is_enriched' => false,
    ]));

    // Strip the derived columns so the row genuinely predates the change.
    Event::withoutSyncingToSearch(fn () => $event->forceFill([
        'match_key' => null,
        'city_slug' => null,
        'local_date' => null,
    ])->save());

    return $event;
}

it('backfills identity keys and a source row for legacy events', function () {
    $event = legacyEvent();

    $this->artisan('eventpulse:dedupe-events', ['--backfill-only' => true])
        ->assertSuccessful();

    $event->refresh();

    expect($event->match_key)->not->toBeNull()
        ->and($event->city_slug)->toBe('timisoara')
        ->and($event->local_date?->toDateString())->toBe('2026-05-10')
        ->and(EventSource::where('event_id', $event->id)->count())->toBe(1);
});

it('writes nothing on a dry run', function () {
    $first = legacyEvent();
    $second = legacyEvent(['source' => 'iabilet', 'source_url' => 'https://m.iabilet.ro/x/']);

    $this->artisan('eventpulse:dedupe-events', ['--dry-run' => true])
        ->assertSuccessful();

    expect($first->fresh()->match_key)->toBeNull()
        ->and($second->fresh()->merged_into_id)->toBeNull()
        ->and(EventSource::count())->toBe(0);
});

it('merges two legacy copies of the same event into one canonical row', function () {
    $fromAggregator = legacyEvent([
        'source' => 'allevents',
        'source_url' => 'https://allevents.in/timisoara/concert-phoenix/1',
    ]);

    $fromTicketing = legacyEvent([
        'source' => 'iabilet',
        'source_url' => 'https://m.iabilet.ro/bilete/concert-phoenix/',
        'description' => 'Legendary Romanian rock band.',
    ]);

    $this->artisan('eventpulse:dedupe-events')->assertSuccessful();

    // iabilet outranks allevents, so it becomes the canonical row.
    expect(Event::canonical()->count())->toBe(1)
        ->and($fromTicketing->fresh()->merged_into_id)->toBeNull()
        ->and($fromAggregator->fresh()->merged_into_id)->toBe($fromTicketing->id);
});

it('leaves genuinely different events on the same night alone', function () {
    legacyEvent(['title' => 'Concert Phoenix']);
    legacyEvent([
        'title' => 'Stand-up Comedy cu Micutzu',
        'source' => 'iabilet',
        'source_url' => 'https://m.iabilet.ro/bilete/standup/',
    ]);

    $this->artisan('eventpulse:dedupe-events', ['--fuzzy' => true])->assertSuccessful();

    expect(Event::canonical()->count())->toBe(2);
});

it('merges near-miss titles only on the fuzzy pass', function () {
    legacyEvent(['title' => 'Trupa Phoenix in concert']);
    legacyEvent([
        'title' => 'Trupa Phoenix in concert la Casa Tineretului',
        'source' => 'iabilet',
        'source_url' => 'https://m.iabilet.ro/bilete/phoenix/',
    ]);

    $this->artisan('eventpulse:dedupe-events')->assertSuccessful();
    expect(Event::canonical()->count())->toBe(2);

    $this->artisan('eventpulse:dedupe-events', ['--fuzzy' => true])->assertSuccessful();
    expect(Event::canonical()->count())->toBe(1);
});

it('is idempotent when run twice', function () {
    legacyEvent(['source' => 'allevents', 'source_url' => 'https://allevents.in/x/1']);
    legacyEvent(['source' => 'iabilet', 'source_url' => 'https://m.iabilet.ro/x/']);

    $this->artisan('eventpulse:dedupe-events', ['--fuzzy' => true])->assertSuccessful();

    $afterFirst = [
        'canonical' => Event::canonical()->count(),
        'total' => Event::count(),
        'sources' => EventSource::count(),
    ];

    $this->artisan('eventpulse:dedupe-events', ['--fuzzy' => true])->assertSuccessful();

    expect([
        'canonical' => Event::canonical()->count(),
        'total' => Event::count(),
        'sources' => EventSource::count(),
    ])->toBe($afterFirst);
});

it('limits the run to one city when asked', function () {
    legacyEvent(['city' => 'Timișoara']);
    $cluj = legacyEvent([
        'city' => 'Cluj-Napoca',
        'source' => 'iabilet',
        'source_url' => 'https://m.iabilet.ro/cluj/',
    ]);

    // Phase 0 needs city_slug populated before --city can filter on it.
    $this->artisan('eventpulse:dedupe-events', ['--backfill-only' => true])->assertSuccessful();

    $this->artisan('eventpulse:dedupe-events', ['--city' => 'timisoara', '--fuzzy' => true])
        ->assertSuccessful();

    expect($cluj->fresh()->merged_into_id)->toBeNull();
});
