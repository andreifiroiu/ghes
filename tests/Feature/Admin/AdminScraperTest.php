<?php

declare(strict_types=1);

use App\Jobs\RunScraperJob;
use App\Models\ScraperRun;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->withoutVite();
    $this->admin = User::factory()->create();
    config(['eventpulse.admin_emails' => [$this->admin->email]]);
});

it('renders the scrapers admin page', function () {
    $this->actingAs($this->admin)->get('/admin/scrapers')
        ->assertStatus(200)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Scrapers')
            ->has('cities')
            ->has('adapters'));
});

it('dispatches scraper jobs for a city via the orchestrator', function () {
    Queue::fake();

    $this->actingAs($this->admin)
        ->post('/admin/scrapers/run', ['city' => 'timisoara'])
        ->assertRedirect(route('admin.scrapers.index'));

    Queue::assertPushed(RunScraperJob::class);
});

it('dispatches a single configured source as a job', function () {
    Queue::fake();

    $this->actingAs($this->admin)
        ->post('/admin/scrapers/run', ['city' => 'timisoara', 'source' => 'allevents'])
        ->assertRedirect(route('admin.scrapers.index'));

    Queue::assertPushed(RunScraperJob::class, 1);
});

it('rejects a source that is not configured for the chosen city', function () {
    Queue::fake();

    config(['eventpulse.cities.timisoara.sources' => [
        ['adapter' => 'allevents', 'url' => 'https://example.test', 'enabled' => true, 'interval_hours' => 6],
    ]]);

    $this->actingAs($this->admin)
        ->post('/admin/scrapers/run', ['city' => 'timisoara', 'source' => 'iabilet'])
        ->assertSessionHas('error', "Source 'iabilet' is not configured for 'timisoara'.");

    Queue::assertNothingPushed();
});

it('scopes the offered sources to each city', function () {
    config(['eventpulse.cities.timisoara.sources' => [
        ['adapter' => 'allevents', 'url' => 'https://example.test', 'enabled' => true, 'interval_hours' => 6],
        ['adapter' => 'onevent', 'url' => 'https://example.test', 'enabled' => false, 'interval_hours' => 6],
    ]]);

    $this->actingAs($this->admin)->get('/admin/scrapers')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('adapters.timisoara', 2)
            ->where('adapters.timisoara.0', ['adapter' => 'allevents', 'enabled' => true])
            ->where('adapters.timisoara.1', ['adapter' => 'onevent', 'enabled' => false]));
});

it('paginates the scraper runs list at the configured page size', function () {
    config(['eventpulse.pagination.admin_scraper_runs' => 2]);
    ScraperRun::factory()->count(3)->create();

    $this->actingAs($this->admin)->get('/admin/scrapers')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('runs.data', 2)
            ->where('runs.per_page', 2));
});
