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
