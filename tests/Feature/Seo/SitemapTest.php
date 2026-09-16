<?php

declare(strict_types=1);

use App\Models\Event;
use App\Services\Seo\SitemapGenerator;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

it('serves the sitemap as XML', function () {
    $this->get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=utf-8');
});

it('produces a well-formed urlset in the sitemap namespace', function () {
    Event::factory()->create(['starts_at' => now()->addWeek()]);

    $xml = new SimpleXMLElement((string) $this->get('/sitemap.xml')->getContent());

    expect($xml->getName())->toBe('urlset')
        ->and($xml->getNamespaces()[''])->toBe(SitemapGenerator::NAMESPACE);
});

it('starts with the XML declaration and no leading whitespace', function () {
    // A byte before the declaration makes the document invalid, and a Blade
    // view is the usual way one gets there.
    expect((string) $this->get('/sitemap.xml')->getContent())->toStartWith('<?xml version="1.0" encoding="UTF-8"?>');
});

it('lists the static public pages', function () {
    $body = (string) $this->get('/sitemap.xml')->getContent();

    expect($body)->toContain('<loc>'.url('/').'</loc>')
        ->and($body)->toContain('<loc>'.route('events.index').'</loc>');
});

it('lists an upcoming event', function () {
    $event = Event::factory()->create(['starts_at' => now()->addWeek()]);

    expect((string) $this->get('/sitemap.xml')->getContent())
        ->toContain(route('events.show', $event));
});

it('omits a past event', function () {
    $past = Event::factory()->create(['starts_at' => now()->subWeek()]);

    expect((string) $this->get('/sitemap.xml')->getContent())
        ->not->toContain(route('events.show', $past));
});

it('omits a hidden event', function () {
    $hidden = Event::factory()->create(['starts_at' => now()->addWeek(), 'is_hidden' => true]);

    expect((string) $this->get('/sitemap.xml')->getContent())
        ->not->toContain(route('events.show', $hidden));
});

it('omits an event that was merged into another', function () {
    $survivor = Event::factory()->create(['starts_at' => now()->addWeek()]);
    $duplicate = Event::factory()->create([
        'starts_at' => now()->addWeek(),
        'merged_into_id' => $survivor->id,
    ]);

    $body = (string) $this->get('/sitemap.xml')->getContent();

    // Submitting both would advertise one page under two URLs, which is the
    // duplicate content the deduplicator exists to prevent.
    expect($body)->toContain(route('events.show', $survivor))
        ->and($body)->not->toContain(route('events.show', $duplicate));
});

it('carries the event updated_at as lastmod', function () {
    $event = Event::factory()->create(['starts_at' => now()->addWeek()]);

    expect((string) $this->get('/sitemap.xml')->getContent())
        ->toContain('<lastmod>'.$event->updated_at->toAtomString().'</lastmod>');
});

it('never advertises a URL that is served with noindex', function () {
    config(['eventpulse.seo.sitemap.static' => ['/' => 'daily', 'dashboard' => 'daily']]);

    expect((string) $this->get('/sitemap.xml')->getContent())
        ->not->toContain('<loc>'.url('dashboard').'</loc>');
});

it('lists no URL twice', function () {
    Event::factory()->count(3)->create(['starts_at' => now()->addWeek()]);

    $locs = array_column(app(SitemapGenerator::class)->urls(), 'loc');

    expect($locs)->toHaveCount(count(array_unique($locs)));
});

it('honours the event ceiling', function () {
    config(['eventpulse.seo.sitemap.max_events' => 2]);

    Event::factory()->count(4)->create(['starts_at' => now()->addWeek()]);

    // Two static routes plus the two events the ceiling allows.
    expect(app(SitemapGenerator::class)->urls())->toHaveCount(4);
});

it('serves the cached document rather than rebuilding it per request', function () {
    $this->get('/sitemap.xml')->assertOk();

    $late = Event::factory()->create(['starts_at' => now()->addWeek()]);

    expect((string) $this->get('/sitemap.xml')->getContent())
        ->not->toContain(route('events.show', $late));

    app(SitemapGenerator::class)->forget();

    expect((string) $this->get('/sitemap.xml')->getContent())
        ->toContain(route('events.show', $late));
});
