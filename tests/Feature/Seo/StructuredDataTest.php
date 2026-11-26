<?php

declare(strict_types=1);

use App\Models\Event;
use App\Services\Seo\StructuredData;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();
});

/**
 * Every JSON-LD node in a rendered page, decoded.
 *
 * @return array<int, array<string, mixed>>
 */
function jsonLdNodes(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

    return array_map(
        static fn (string $json): array => json_decode($json, true, flags: JSON_THROW_ON_ERROR),
        $matches[1],
    );
}

it('puts the organization node on every public page', function () {
    $nodes = jsonLdNodes((string) $this->get('/')->getContent());

    $organization = collect($nodes)->firstWhere('@type', 'Organization');

    expect($organization)->not->toBeNull()
        ->and($organization['@id'])->toBe(url('/#organization'))
        ->and($organization['url'])->toBe(url('/'));
});

it('omits a legal name nobody configured rather than inventing one', function () {
    config(['eventpulse.seo.organization.legal_name' => null]);

    expect(app(StructuredData::class)->organization())->not->toHaveKey('legalName');
});

it('puts the website node on the landing page only', function () {
    expect(collect(jsonLdNodes((string) $this->get('/')->getContent()))->firstWhere('@type', 'WebSite'))
        ->not->toBeNull();

    expect(collect(jsonLdNodes((string) $this->get(route('events.index'))->getContent()))->firstWhere('@type', 'WebSite'))
        ->toBeNull();
});

it('describes an event with the columns it has', function () {
    $event = Event::factory()->create([
        'title' => 'Concert simfonic',
        'description' => 'Filarmonica Banatul.',
        'venue' => 'Sala Capitol',
        'address' => 'Strada Mărășești 2',
        'city' => 'Timișoara',
        'latitude' => 45.7489,
        'longitude' => 21.2087,
        'starts_at' => now()->addWeek(),
        'ends_at' => now()->addWeek()->addHours(2),
        'price_min' => 50,
        'currency' => 'RON',
        'is_free' => false,
        'image_url' => 'https://cdn.example/poster.jpg',
    ]);

    $node = collect(jsonLdNodes((string) $this->get(route('events.show', $event))->getContent()))
        ->firstWhere('@type', 'Event');

    expect($node)->not->toBeNull()
        ->and($node['name'])->toBe('Concert simfonic')
        ->and($node['url'])->toBe(route('events.show', $event))
        ->and($node['startDate'])->toBe($event->starts_at->toIso8601String())
        ->and($node['endDate'])->toBe($event->ends_at->toIso8601String())
        ->and($node['image'])->toBe('https://cdn.example/poster.jpg')
        ->and($node['eventStatus'])->toBe('https://schema.org/EventScheduled')
        ->and($node['location']['@type'])->toBe('Place')
        ->and($node['location']['name'])->toBe('Sala Capitol')
        ->and($node['location']['address']['streetAddress'])->toBe('Strada Mărășești 2')
        ->and($node['location']['address']['addressLocality'])->toBe('Timișoara')
        ->and($node['location']['geo'])->toBe([
            '@type' => 'GeoCoordinates',
            'latitude' => 45.7489,
            'longitude' => 21.2087,
        ])
        ->and($node['offers']['price'])->toBe('50.00')
        ->and($node['offers']['priceCurrency'])->toBe('RON');
});

it('prices a free event at zero rather than omitting the offer', function () {
    $event = Event::factory()->create([
        'is_free' => true,
        'price_min' => null,
        'starts_at' => now()->addWeek(),
    ]);

    $node = collect(jsonLdNodes((string) $this->get(route('events.show', $event))->getContent()))
        ->firstWhere('@type', 'Event');

    expect($node['offers']['price'])->toBe('0')
        ->and($node['isAccessibleForFree'])->toBeTrue();
});

it('claims no offer when the price is simply unknown', function () {
    $event = Event::factory()->create([
        'is_free' => false,
        'price_min' => null,
        'price_max' => null,
        'starts_at' => now()->addWeek(),
    ]);

    $node = collect(jsonLdNodes((string) $this->get(route('events.show', $event))->getContent()))
        ->firstWhere('@type', 'Event');

    expect($node)->not->toHaveKey('offers');
});

it('points the offer at the provider rather than at our disallowed redirect', function () {
    // robots.txt disallows /go/, so advertising it as the purchase URL would
    // be a contradiction a validator flags.
    $event = Event::factory()->create([
        'source_url' => 'https://iabilet.ro/bilete/concert-123',
        'is_free' => true,
        'starts_at' => now()->addWeek(),
    ]);

    $node = collect(jsonLdNodes((string) $this->get(route('events.show', $event))->getContent()))
        ->firstWhere('@type', 'Event');

    expect($node['offers']['url'])->toBe('https://iabilet.ro/bilete/concert-123');
});

it('falls back to the city when an event has no venue', function () {
    $event = Event::factory()->create([
        'venue' => null,
        'city' => 'Timișoara',
        'starts_at' => now()->addWeek(),
    ]);

    $node = collect(jsonLdNodes((string) $this->get(route('events.show', $event))->getContent()))
        ->firstWhere('@type', 'Event');

    expect($node['location']['name'])->toBe('Timișoara');
});

it('claims no location at all when there is neither venue nor city', function () {
    // A Place with only coordinates is a pin, not a rich result.
    $event = Event::factory()->create(['venue' => null, 'city' => null, 'starts_at' => now()->addWeek()]);

    $node = collect(jsonLdNodes((string) $this->get(route('events.show', $event))->getContent()))
        ->firstWhere('@type', 'Event');

    expect($node)->not->toHaveKey('location');
});

it('truncates a scraped description rather than dumping a venue boilerplate', function () {
    $event = Event::factory()->create([
        'description' => str_repeat('foarte lung ', 200),
        'starts_at' => now()->addWeek(),
    ]);

    $node = collect(jsonLdNodes((string) $this->get(route('events.show', $event))->getContent()))
        ->firstWhere('@type', 'Event');

    expect(strlen($node['description']))->toBeLessThanOrEqual(503);
});

it('trails breadcrumbs from the home page down to the event', function () {
    $event = Event::factory()->create(['title' => 'Târg de Crăciun', 'starts_at' => now()->addWeek()]);

    $node = collect(jsonLdNodes((string) $this->get(route('events.show', $event))->getContent()))
        ->firstWhere('@type', 'BreadcrumbList');

    expect($node['itemListElement'])->toHaveCount(3)
        ->and($node['itemListElement'][0]['position'])->toBe(1)
        ->and($node['itemListElement'][0]['item'])->toBe(route('home'))
        ->and($node['itemListElement'][2]['name'])->toBe('Târg de Crăciun')
        // The page you are on names no item — it is where the trail ends.
        ->and($node['itemListElement'][2])->not->toHaveKey('item');
});

it('lists the browse results as an ItemList', function () {
    Event::factory()->count(3)->create(['starts_at' => now()->addWeek()]);

    $node = collect(jsonLdNodes((string) $this->get(route('events.index'))->getContent()))
        ->firstWhere('@type', 'ItemList');

    expect($node['numberOfItems'])->toBe(3)
        ->and($node['itemListElement'][0]['position'])->toBe(1)
        ->and($node['itemListElement'][0])->toHaveKey('url');
});

it('omits the ItemList from a filtered browse, which is not offered for indexing', function () {
    Event::factory()->create(['category' => 'music', 'starts_at' => now()->addWeek()]);

    $node = collect(jsonLdNodes((string) $this->get(route('events.index', ['category' => 'music']))->getContent()))
        ->firstWhere('@type', 'ItemList');

    expect($node)->toBeNull();
});

it('keeps Romanian diacritics readable rather than escaping them', function () {
    $event = Event::factory()->create(['title' => 'Spectacol în Timișoara', 'starts_at' => now()->addWeek()]);

    expect((string) $this->get(route('events.show', $event))->getContent())
        ->toContain('"name":"Spectacol în Timișoara"');
});

it('cannot have its JSON-LD block closed early by an event title', function () {
    $event = Event::factory()->create([
        'title' => 'Hack </script><script>alert(1)</script>',
        'starts_at' => now()->addWeek(),
    ]);

    $html = (string) $this->get(route('events.show', $event))->getContent();

    expect($html)->not->toContain('</script><script>alert(1)')
        ->and($html)->toContain('<\/script>');

    // And the document still parses as the nodes we meant to emit.
    expect(collect(jsonLdNodes($html))->firstWhere('@type', 'Event'))->not->toBeNull();
});

it('states no address rather than an empty PostalAddress', function () {
    // A node carrying only its own @type claims to have an address and gives
    // none. PHPStan caught this as a type complaint; it was a real defect.
    $event = Event::factory()->create([
        'venue' => 'Sala Capitol',
        'address' => null,
        'city' => null,
        'starts_at' => now()->addWeek(),
    ]);

    $node = collect(jsonLdNodes((string) $this->get(route('events.show', $event))->getContent()))
        ->firstWhere('@type', 'Event');

    expect($node['location']['name'])->toBe('Sala Capitol')
        ->and($node['location'])->not->toHaveKey('address');
});

it('keeps the country on an address that has a city', function () {
    $event = Event::factory()->create([
        'venue' => 'Sala Capitol',
        'address' => null,
        'city' => 'Timișoara',
        'starts_at' => now()->addWeek(),
    ]);

    $node = collect(jsonLdNodes((string) $this->get(route('events.show', $event))->getContent()))
        ->firstWhere('@type', 'Event');

    expect($node['location']['address'])->toBe([
        '@type' => 'PostalAddress',
        'addressLocality' => 'Timișoara',
        'addressCountry' => 'RO',
    ]);
});
