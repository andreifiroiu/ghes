<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\ScraperRun;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->withoutVite();

    // The landing payload is cached; each test needs to see its own fixtures.
    Cache::flush();
});

it('shows the landing page to a guest', function () {
    $this->get('/')
        ->assertStatus(200)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Landing')
            ->has('events')
            ->has('stats')
            ->has('city'));
});

it('redirects an authenticated user to the dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertRedirect(route('dashboard'));
});

it('lists upcoming events in the preview grid, soonest first', function () {
    $later = Event::factory()->create(['starts_at' => now()->addDays(5)]);
    $sooner = Event::factory()->create(['starts_at' => now()->addDay()]);

    $this->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events', 2)
            ->where('events.0.id', $sooner->id)
            ->where('events.1.id', $later->id));
});

it('shows at most six events', function () {
    Event::factory()->count(9)->create(['starts_at' => now()->addDay()]);

    $this->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('events', 6));
});

it('leaves out past, hidden and merged-duplicate events', function () {
    $visible = Event::factory()->create(['starts_at' => now()->addDay()]);

    Event::factory()->past()->create();
    Event::factory()->create(['starts_at' => now()->addDay(), 'is_hidden' => true]);
    Event::factory()->merged($visible)->create(['starts_at' => now()->addDay()]);

    $this->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events', 1)
            ->where('events.0.id', $visible->id));
});

it('does not expose a reaction on the public preview', function () {
    Event::factory()->create(['starts_at' => now()->addDay()]);

    $this->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page->missing('events.0.current_reaction'));
});

it('renders without events when there is nothing upcoming', function () {
    Event::factory()->past()->create();

    $this->get('/')
        ->assertStatus(200)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events', 0)
            ->where('stats.active', 0));
});

it('counts only upcoming, visible, canonical events as active', function () {
    Event::factory()->count(2)->create(['starts_at' => now()->addDay()]);
    Event::factory()->past()->create();
    Event::factory()->create(['starts_at' => now()->addDay(), 'is_hidden' => true]);

    $this->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('stats.active', 2));
});

it('reports the number of enabled sources for the active city', function () {
    config([
        'eventpulse.default_city' => 'timisoara',
        'eventpulse.cities.timisoara.sources' => [
            ['adapter' => 'iabilet', 'enabled' => true],
            ['adapter' => 'allevents', 'enabled' => true],
            ['adapter' => 'meetup', 'enabled' => false],
        ],
    ]);

    $this->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('stats.sources', 2));
});

it('reports the last finished scraper run as the freshness timestamp', function () {
    ScraperRun::factory()->create(['finished_at' => now()->subHours(3)]);
    $latest = ScraperRun::factory()->create(['finished_at' => now()->subMinutes(6)]);

    $this->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('stats.last_scraped_at', $latest->finished_at->toIso8601String()));
});

it('reports no freshness timestamp when no run has finished', function () {
    ScraperRun::factory()->create(['finished_at' => null]);

    $this->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('stats.last_scraped_at', null));
});

it('counts events added today against the city timezone, not UTC', function () {
    $timezone = (string) config('eventpulse.cities.'.config('eventpulse.default_city').'.timezone');

    // 01:00 in Timișoara is still the previous day in UTC. Binding a local
    // wall-clock boundary against UTC `created_at` values silently drops every
    // event created between local midnight and the UTC offset.
    Carbon::setTestNow(Carbon::parse('2026-09-02 01:00', $timezone));

    Event::factory()->create(['starts_at' => now()->addDays(3)]);

    $this->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('stats.added_today', 1));
});
