<?php

declare(strict_types=1);

use App\Models\ScraperRun;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->withoutVite();
    $this->admin = User::factory()->create();
    config(['eventpulse.admin_emails' => [$this->admin->email]]);
    config(['eventpulse.cities.timisoara.sources' => [
        ['adapter' => 'allevents', 'url' => 'https://example.test', 'enabled' => true, 'interval_hours' => 6],
    ]]);
});

it('renders the detail page for a configured source', function () {
    ScraperRun::factory()->create([
        'source' => 'allevents',
        'city' => 'timisoara',
        'status' => 'completed',
        'started_at' => now()->subHour(),
    ]);

    $this->actingAs($this->admin)->get('/admin/scrapers/timisoara/allevents')
        ->assertStatus(200)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Scrapers/Show')
            ->where('source.adapter', 'allevents')
            ->where('source.city', 'timisoara')
            ->where('source.url', 'https://example.test')
            ->where('source.enabled', true)
            ->has('stats.days')
            ->has('stats.totals')
            ->has('stats.health')
            ->has('runs.data', 1)
            // The status enum has to reach the page as its string value — the
            // badge component switches on it.
            ->where('runs.data.0.status', 'completed')
            ->where('stats.health.last_run.status', 'completed'));
});

it('404s for a city that is not configured', function () {
    $this->actingAs($this->admin)
        ->get('/admin/scrapers/atlantis/allevents')
        ->assertStatus(404);
});

it('404s for a source that city has not configured', function () {
    $this->actingAs($this->admin)
        ->get('/admin/scrapers/timisoara/iabilet')
        ->assertStatus(404);
});

it('zero-fills days on which the source did not run', function () {
    ScraperRun::factory()->create([
        'source' => 'allevents',
        'city' => 'timisoara',
        'status' => 'completed',
        'events_found' => 12,
        'started_at' => now()->subDays(3),
    ]);

    $this->actingAs($this->admin)->get('/admin/scrapers/timisoara/allevents?range=7')
        ->assertInertia(function (AssertableInertia $page) {
            $days = $page->toArray()['props']['stats']['days'];

            // Every day in the window is present, not just the ones with runs —
            // a source that stopped running has to show a gap, not vanish.
            expect($days)->toHaveCount(7)
                ->and(collect($days)->sum('found'))->toBe(12)
                ->and(collect($days)->where('runs', 0)->count())->toBe(6);
        });
});

it('buckets a run into the local day, not the UTC day', function () {
    // 22:30 UTC is 00:30 the next day in Europe/Bucharest (UTC+2), so this run
    // belongs to tomorrow's local bucket.
    $utcEvening = now()->setTimezone('UTC')->subDay()->setTime(22, 30);

    ScraperRun::factory()->create([
        'source' => 'allevents',
        'city' => 'timisoara',
        'status' => 'completed',
        'events_found' => 5,
        'started_at' => $utcEvening,
    ]);

    $localDay = $utcEvening->copy()->setTimezone('Europe/Bucharest')->toDateString();

    $this->actingAs($this->admin)->get('/admin/scrapers/timisoara/allevents?range=7')
        ->assertInertia(function (AssertableInertia $page) use ($localDay) {
            $days = collect($page->toArray()['props']['stats']['days']);

            expect($days->firstWhere('day', $localDay)['found'])->toBe(5);
        });
});

it('clamps an unsupported range to the default', function () {
    $this->actingAs($this->admin)->get('/admin/scrapers/timisoara/allevents?range=999')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('range', 30)
            ->has('stats.days', 30));
});

it('honours a supported range', function () {
    $this->actingAs($this->admin)->get('/admin/scrapers/timisoara/allevents?range=7')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('range', 7)
            ->has('stats.days', 7));
});

it('counts a failed run as a failure but not as events', function () {
    ScraperRun::factory()->failed()->create([
        'source' => 'allevents',
        'city' => 'timisoara',
        'events_found' => 0,
        'events_created' => 0,
        'started_at' => now()->subHour(),
    ]);

    $this->actingAs($this->admin)->get('/admin/scrapers/timisoara/allevents?range=7')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('stats.totals.failed', 1)
            ->where('stats.totals.completed', 0)
            ->where('stats.totals.found', 0)
            ->where('stats.health.success_rate', 0)
            ->where('stats.health.consecutive_failures', 1));
});

it('reports the consecutive failure streak from the most recent run backwards', function () {
    ScraperRun::factory()->create([
        'source' => 'allevents', 'city' => 'timisoara',
        'status' => 'failed', 'started_at' => now()->subHours(3),
    ]);
    ScraperRun::factory()->create([
        'source' => 'allevents', 'city' => 'timisoara',
        'status' => 'completed', 'started_at' => now()->subHours(2),
    ]);
    ScraperRun::factory()->create([
        'source' => 'allevents', 'city' => 'timisoara',
        'status' => 'failed', 'started_at' => now()->subHour(),
    ]);

    // Only the trailing failure counts — the success in between resets it.
    $this->actingAs($this->admin)->get('/admin/scrapers/timisoara/allevents')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('stats.health.consecutive_failures', 1)
            ->where('stats.health.last_success.status', 'completed'));
});

it('ignores runs belonging to another city', function () {
    config(['eventpulse.cities.cluj' => [
        'label' => 'Cluj-Napoca',
        'timezone' => 'Europe/Bucharest',
        'coordinates' => [46.7712, 23.6236],
        'radius_km' => 25,
        'sources' => [
            ['adapter' => 'allevents', 'url' => 'https://example.test', 'enabled' => true, 'interval_hours' => 6],
        ],
    ]]);

    ScraperRun::factory()->create([
        'source' => 'allevents', 'city' => 'cluj',
        'status' => 'completed', 'events_found' => 40, 'started_at' => now()->subHour(),
    ]);

    $this->actingAs($this->admin)->get('/admin/scrapers/timisoara/allevents?range=7')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('stats.totals.found', 0)
            ->has('runs.data', 0));
});
