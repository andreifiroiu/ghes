<?php

declare(strict_types=1);

use App\DTOs\RawEvent;
use App\Jobs\ClassifyEventJob;
use App\Models\Event;
use App\Models\EventSource;
use App\Services\Processing\EventPipeline;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->pipeline = app(EventPipeline::class);
});

/**
 * One real 20:00 (Europe/Bucharest) concert on 2026-05-10, as reported by a
 * given provider. Defaults mirror how that provider actually stores it.
 *
 * @param  array<string, mixed>  $overrides
 */
function providerEvent(string $source, array $overrides = []): RawEvent
{
    $defaults = [
        // Date only, stored as local midnight.
        'iabilet' => [
            'title' => 'Concert Phoenix',
            'source_url' => 'https://m.iabilet.ro/bilete/concert-phoenix-98765/',
            'source_id' => 'concert-phoenix-98765',
            'starts_at' => '2026-05-09 21:00:00',
            'venue' => 'Casa Tineretului',
        ],
        // Romanian wall clock; carries the real time.
        'zilesinopti' => [
            'title' => 'Concert Phoenix',
            'source_url' => 'https://zilesinopti.ro/evenimente/concert-phoenix/',
            'source_id' => 'concert-phoenix',
            'starts_at' => '2026-05-10 17:00:00',
            'venue' => 'Casa Tineretului',
        ],
        // Aggregator: decorated title, venue with the city appended.
        'allevents' => [
            'title' => 'Concert Phoenix - Live in Timisoara',
            'source_url' => 'https://allevents.in/timisoara/concert-phoenix/123',
            'source_id' => '123',
            'starts_at' => '2026-05-10 17:00:00',
            'venue' => 'Casa Tineretului, Timisoara',
        ],
    ];

    $values = array_merge($defaults[$source] ?? $defaults['zilesinopti'], $overrides);

    return new RawEvent(
        title: $values['title'],
        description: $values['description'] ?? null,
        sourceUrl: $values['source_url'],
        sourceId: $values['source_id'],
        source: $source,
        venue: $values['venue'] ?? null,
        address: $values['address'] ?? null,
        city: array_key_exists('city', $values) ? $values['city'] : 'Timișoara',
        startsAt: $values['starts_at'],
        endsAt: null,
        priceMin: $values['price_min'] ?? null,
        priceMax: $values['price_max'] ?? null,
        currency: 'RON',
        isFree: false,
        imageUrl: $values['image_url'] ?? null,
        metadata: $values['metadata'] ?? [],
    );
}

// ---------------------------------------------------------------------------
// Creating
// ---------------------------------------------------------------------------

it('creates a canonical event with one attached source on first sight', function () {
    $event = $this->pipeline->process(providerEvent('zilesinopti'), 'timisoara');

    expect($event)->not->toBeNull()
        ->and($event->wasRecentlyCreated)->toBeTrue()
        ->and($event->sources_count)->toBe(1)
        ->and(Event::count())->toBe(1)
        ->and(EventSource::where('event_id', $event->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Re-scrape idempotency
// ---------------------------------------------------------------------------

it('converges instead of duplicating when the same scrape runs twice', function () {
    $first = $this->pipeline->process(providerEvent('zilesinopti'), 'timisoara');
    $second = $this->pipeline->process(providerEvent('zilesinopti'), 'timisoara');

    expect(Event::count())->toBe(1)
        ->and(EventSource::count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and($second->wasRecentlyCreated)->toBeFalse();
});

it('refreshes price and description when the source updates its listing', function () {
    $this->pipeline->process(providerEvent('zilesinopti'), 'timisoara');

    $this->pipeline->process(providerEvent('zilesinopti', [
        'description' => 'Now with a support act.',
        'price_min' => 75.0,
        'price_max' => 150.0,
    ]), 'timisoara');

    $event = Event::sole();

    expect($event->description)->toBe('Now with a support act.')
        ->and($event->price_min)->toBe(75.0)
        ->and($event->price_max)->toBe(150.0);
});

it('ignores a tracking query string when recognising its own listing', function () {
    $this->pipeline->process(providerEvent('zilesinopti'), 'timisoara');
    $this->pipeline->process(providerEvent('zilesinopti', [
        'source_url' => 'https://zilesinopti.ro/evenimente/concert-phoenix/?utm_source=newsletter',
    ]), 'timisoara');

    expect(Event::count())->toBe(1)
        ->and(EventSource::count())->toBe(1);
});

it('keeps a recurring event that reuses one URL as separate occurrences', function () {
    $this->pipeline->process(providerEvent('zilesinopti'), 'timisoara');
    $this->pipeline->process(providerEvent('zilesinopti', [
        'starts_at' => '2026-05-17 17:00:00',
    ]), 'timisoara');

    expect(Event::count())->toBe(2)
        ->and(EventSource::count())->toBe(2);
});

// ---------------------------------------------------------------------------
// Cross-provider merging
// ---------------------------------------------------------------------------

it('stores one event when three providers report the same concert', function () {
    $this->pipeline->process(providerEvent('iabilet'), 'timisoara');
    $this->pipeline->process(providerEvent('zilesinopti'), 'timisoara');
    $this->pipeline->process(providerEvent('allevents'), 'timisoara');

    $event = Event::sole();

    expect(Event::count())->toBe(1)
        ->and($event->sources_count)->toBe(3)
        ->and($event->sources()->pluck('source')->sort()->values()->all())
        ->toBe(['allevents', 'iabilet', 'zilesinopti']);
});

it('fills gaps from a later provider without discarding what it already had', function () {
    $this->pipeline->process(providerEvent('iabilet'), 'timisoara');

    $this->pipeline->process(providerEvent('allevents', [
        'description' => 'Legendary Romanian rock band.',
        'image_url' => 'https://cdn.allevents.in/phoenix.jpg',
        'price_min' => 60.0,
    ]), 'timisoara');

    $event = Event::sole();

    expect($event->description)->toBe('Legendary Romanian rock band.')
        ->and($event->image_url)->toBe('https://cdn.allevents.in/phoenix.jpg')
        ->and($event->price_min)->toBe(60.0);
});

it('upgrades a date-only start time with a real time from another provider', function () {
    // iabilet publishes the date only, as local midnight.
    $created = $this->pipeline->process(providerEvent('iabilet'), 'timisoara');
    expect($created->starts_at->setTimezone('Europe/Bucharest')->format('H:i'))->toBe('00:00');

    $this->pipeline->process(providerEvent('zilesinopti'), 'timisoara');

    expect(Event::sole()->starts_at->setTimezone('Europe/Bucharest')->format('H:i'))->toBe('20:00');
});

it('lets a higher-priority source take over the headline fields', function () {
    // allevents (40) first, then iabilet (80).
    $this->pipeline->process(providerEvent('allevents'), 'timisoara');
    $this->pipeline->process(providerEvent('iabilet'), 'timisoara');

    $event = Event::sole();

    expect($event->source)->toBe('iabilet')
        ->and($event->title)->toBe('Concert Phoenix');
});

it('does not let a lower-priority source take over the headline fields', function () {
    // iabilet (80) first, then allevents (40).
    $this->pipeline->process(providerEvent('iabilet'), 'timisoara');
    $this->pipeline->process(providerEvent('allevents'), 'timisoara');

    $event = Event::sole();

    expect($event->source)->toBe('iabilet')
        ->and($event->title)->toBe('Concert Phoenix');
});

it('keeps genuinely different events on the same night apart', function () {
    $this->pipeline->process(providerEvent('zilesinopti'), 'timisoara');
    $this->pipeline->process(providerEvent('zilesinopti', [
        'title' => 'Stand-up Comedy cu Micutzu',
        'source_url' => 'https://zilesinopti.ro/evenimente/standup-micutzu/',
        'source_id' => 'standup-micutzu',
    ]), 'timisoara');

    expect(Event::count())->toBe(2);
});

// ---------------------------------------------------------------------------
// Follow-up jobs
// ---------------------------------------------------------------------------

it('queues classification once for an event reported by three providers', function () {
    Queue::fake();

    $this->pipeline->process(providerEvent('iabilet'), 'timisoara');
    $this->pipeline->process(providerEvent('zilesinopti'), 'timisoara');
    $this->pipeline->process(providerEvent('allevents'), 'timisoara');

    Queue::assertPushed(ClassifyEventJob::class, 1);
});

// ---------------------------------------------------------------------------
// Concurrency
// ---------------------------------------------------------------------------

it('enriches rather than throwing when another worker won the insert race', function () {
    $raw = providerEvent('zilesinopti');

    // Simulate the loser of a race: the winning row already exists by the time
    // this call reaches its insert.
    $winner = $this->pipeline->process($raw, 'timisoara');

    $result = $this->pipeline->process($raw, 'timisoara');

    expect($result->id)->toBe($winner->id)
        ->and(Event::count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Merged events stay out of the way
// ---------------------------------------------------------------------------

it('attaches a new source to the canonical event when the match was merged away', function () {
    $canonical = $this->pipeline->process(providerEvent('iabilet'), 'timisoara');

    $orphan = Event::factory()->create([
        'title' => 'Concert Phoenix',
        'city' => 'Timișoara',
        'starts_at' => '2026-05-10 17:00:00',
        'merged_into_id' => $canonical->id,
    ]);

    EventSource::factory()->create([
        'event_id' => $orphan->id,
        'source' => 'allevents',
        'url_key' => 'https://allevents.in/timisoara/concert-phoenix/123',
        'source_id' => '123',
        'occurrence_key' => '2026-05-10',
    ]);

    $result = $this->pipeline->process(providerEvent('allevents'), 'timisoara');

    expect($result->id)->toBe($canonical->id)
        ->and($result->merged_into_id)->toBeNull();
});
