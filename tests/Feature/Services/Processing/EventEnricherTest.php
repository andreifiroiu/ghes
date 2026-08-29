<?php

declare(strict_types=1);

use App\Models\Event;
use App\Services\Processing\EventEnricher;
use Illuminate\Support\Facades\Http;

function makeEnricher(): EventEnricher
{
    // Resolve from the container so the injected HTTP factory is the faked singleton.
    return app(EventEnricher::class);
}

it('geocodes an event via Nominatim and stores coordinates and neighborhood', function () {
    config(['eventpulse.geocoding.provider' => 'nominatim']);

    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            [
                'lat' => '45.7489',
                'lon' => '21.2087',
                'address' => ['suburb' => 'Cetate', 'city' => 'Timișoara'],
            ],
        ]),
    ]);

    $event = Event::factory()->create([
        'address' => 'Piața Victoriei 2',
        'city' => 'Timișoara',
        'is_geocoded' => false,
        'latitude' => null,
        'longitude' => null,
        'neighborhood' => null,
    ]);

    makeEnricher()->enrichGeocoding($event);

    $event->refresh();
    expect((float) $event->latitude)->toBe(45.7489)
        ->and((float) $event->longitude)->toBe(21.2087)
        ->and($event->neighborhood)->toBe('Cetate')
        ->and($event->is_geocoded)->toBeTrue();

    Http::assertSent(fn ($request) => $request->hasHeader('User-Agent')
        && str_contains($request->url(), 'nominatim.openstreetmap.org'));
});

it('geocodes an event via Google when configured', function () {
    config([
        'eventpulse.geocoding.provider' => 'google',
        'eventpulse.geocoding.google_key' => 'test-key',
    ]);

    Http::fake([
        'maps.googleapis.com/*' => Http::response([
            'status' => 'OK',
            'results' => [[
                'geometry' => ['location' => ['lat' => 46.7712, 'lng' => 23.6236]],
                'address_components' => [
                    ['long_name' => 'Centru', 'types' => ['neighborhood']],
                ],
            ]],
        ]),
    ]);

    $event = Event::factory()->create([
        'address' => 'Strada Memorandumului 28',
        'city' => 'Cluj-Napoca',
        'is_geocoded' => false,
        'latitude' => null,
        'longitude' => null,
    ]);

    makeEnricher()->enrichGeocoding($event);

    $event->refresh();
    expect((float) $event->latitude)->toBe(46.7712)
        ->and((float) $event->longitude)->toBe(23.6236)
        ->and($event->neighborhood)->toBe('Centru')
        ->and($event->is_geocoded)->toBeTrue();
});

it('skips events that are already geocoded', function () {
    Http::fake();

    $event = Event::factory()->create([
        'is_geocoded' => true,
        'latitude' => 1.0,
        'longitude' => 2.0,
    ]);

    makeEnricher()->enrichGeocoding($event);

    Http::assertNothingSent();
    $event->refresh();
    expect((float) $event->latitude)->toBe(1.0);
});

it('marks geocoded without calling the API when there is no location', function () {
    Http::fake();

    $event = Event::factory()->create([
        'address' => null,
        'venue' => null,
        'city' => null,
        'is_geocoded' => false,
        'latitude' => null,
    ]);

    makeEnricher()->enrichGeocoding($event);

    Http::assertNothingSent();
    $event->refresh();
    expect($event->is_geocoded)->toBeTrue()
        ->and($event->latitude)->toBeNull();
});

it('marks geocoded with null coordinates when no result is found', function () {
    config(['eventpulse.geocoding.provider' => 'nominatim']);
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response([])]);

    $event = Event::factory()->create([
        'address' => 'Nowhere',
        'city' => 'Atlantis',
        'is_geocoded' => false,
        'latitude' => null,
        'longitude' => null,
    ]);

    makeEnricher()->enrichGeocoding($event);

    $event->refresh();
    expect($event->is_geocoded)->toBeTrue()
        ->and($event->latitude)->toBeNull();
});

it('swallows errors and marks geocoded when the provider throws', function () {
    config(['eventpulse.geocoding.provider' => 'nominatim']);
    Http::fake(function () {
        throw new RuntimeException('network down');
    });

    $event = Event::factory()->create([
        'address' => 'Piața Unirii',
        'city' => 'Timișoara',
        'is_geocoded' => false,
        'latitude' => null,
    ]);

    makeEnricher()->enrichGeocoding($event);

    $event->refresh();
    expect($event->is_geocoded)->toBeTrue()
        ->and($event->latitude)->toBeNull();
});

// ---------------------------------------------------------------------------
// enrichMetadata()
// ---------------------------------------------------------------------------

it('enriches description and image from Open Graph tags', function () {
    Http::fake([
        'example.com/*' => Http::response(
            '<html><head>'
            .'<meta property="og:description" content="An evening of live jazz.">'
            .'<meta property="og:image" content="https://example.com/img/jazz.jpg">'
            .'</head><body></body></html>'
        ),
    ]);

    $event = Event::factory()->create([
        'source_url' => 'https://example.com/events/jazz',
        'description' => null,
        'image_url' => null,
        'is_enriched' => false,
    ]);

    makeEnricher()->enrichMetadata($event);

    $event->refresh();
    expect($event->description)->toBe('An evening of live jazz.')
        ->and($event->image_url)->toBe('https://example.com/img/jazz.jpg')
        ->and($event->is_enriched)->toBeTrue()
        ->and($event->metadata['og_image'] ?? null)->toBe('https://example.com/img/jazz.jpg');
});

it('extracts description and image from JSON-LD when there are no OG tags', function () {
    $jsonLd = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Event',
        'name' => 'Jazz Night',
        'description' => 'Structured jazz description.',
        'image' => 'https://example.com/img/ld.jpg',
        'organizer' => ['@type' => 'Organization', 'name' => 'Club X'],
    ]);

    Http::fake([
        'example.com/*' => Http::response(
            '<html><head><script type="application/ld+json">'.$jsonLd.'</script></head><body></body></html>'
        ),
    ]);

    $event = Event::factory()->create([
        'source_url' => 'https://example.com/events/jazz',
        'description' => null,
        'image_url' => null,
        'is_enriched' => false,
    ]);

    makeEnricher()->enrichMetadata($event);

    $event->refresh();
    expect($event->description)->toBe('Structured jazz description.')
        ->and($event->image_url)->toBe('https://example.com/img/ld.jpg')
        ->and($event->metadata)->toHaveKey('jsonld_organizer');
});

it('does not overwrite an existing description or image', function () {
    Http::fake([
        'example.com/*' => Http::response(
            '<html><head>'
            .'<meta property="og:description" content="New description">'
            .'<meta property="og:image" content="https://example.com/new.jpg">'
            .'</head></html>'
        ),
    ]);

    $event = Event::factory()->create([
        'source_url' => 'https://example.com/x',
        'description' => 'Original description',
        'image_url' => 'https://example.com/original.jpg',
        'is_enriched' => false,
    ]);

    makeEnricher()->enrichMetadata($event);

    $event->refresh();
    expect($event->description)->toBe('Original description')
        ->and($event->image_url)->toBe('https://example.com/original.jpg')
        ->and($event->is_enriched)->toBeTrue();
});

it('skips events that are already enriched', function () {
    Http::fake();

    $event = Event::factory()->create(['is_enriched' => true]);

    makeEnricher()->enrichMetadata($event);

    Http::assertNothingSent();
});

it('marks enriched when the source page fails to load', function () {
    Http::fake(['example.com/*' => Http::response('', 500)]);

    $event = Event::factory()->create([
        'source_url' => 'https://example.com/x',
        'description' => null,
        'is_enriched' => false,
    ]);

    makeEnricher()->enrichMetadata($event);

    $event->refresh();
    expect($event->is_enriched)->toBeTrue()
        ->and($event->description)->toBeNull();
});

it('swallows exceptions during metadata enrichment', function () {
    Http::fake(function () {
        throw new RuntimeException('boom');
    });

    $event = Event::factory()->create([
        'source_url' => 'https://example.com/x',
        'is_enriched' => false,
    ]);

    makeEnricher()->enrichMetadata($event);

    $event->refresh();
    expect($event->is_enriched)->toBeTrue();
});
