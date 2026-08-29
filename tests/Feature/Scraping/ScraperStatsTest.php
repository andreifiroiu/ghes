<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\ScraperRun;
use App\Services\Scraping\ScraperStats;

beforeEach(function () {
    $this->stats = app(ScraperStats::class);
});

it('returns a fully zero-filled window when the source has never run', function () {
    $result = $this->stats->forSource('timisoara', 'allevents', 7);

    expect($result['days'])->toHaveCount(7)
        ->and($result['totals']['runs'])->toBe(0)
        ->and($result['totals']['found'])->toBe(0)
        // No resolved runs means there is no rate to report — zero would imply
        // the source failed, which is a different and much louder claim.
        ->and($result['health']['success_rate'])->toBeNull()
        ->and($result['health']['last_run'])->toBeNull()
        ->and($result['health']['avg_duration_seconds'])->toBeNull();
});

it('sums each counter across several runs on the same day', function () {
    ScraperRun::factory()->count(2)->create([
        'source' => 'allevents',
        'city' => 'timisoara',
        'status' => 'completed',
        'events_found' => 10,
        'events_created' => 4,
        'events_updated' => 3,
        'events_skipped' => 1,
        'started_at' => now()->subHours(2),
    ]);

    $result = $this->stats->forSource('timisoara', 'allevents', 7);

    expect($result['totals'])
        ->found->toBe(20)
        ->created->toBe(8)
        ->updated->toBe(6)
        ->skipped->toBe(2)
        ->runs->toBe(2)
        ->completed->toBe(2);
});

it('excludes runs older than the window', function () {
    ScraperRun::factory()->create([
        'source' => 'allevents', 'city' => 'timisoara', 'status' => 'completed',
        'events_found' => 99, 'started_at' => now()->subDays(20),
    ]);

    $result = $this->stats->forSource('timisoara', 'allevents', 7);

    expect($result['totals']['found'])->toBe(0)
        // ...but "last run" deliberately reaches past the window, otherwise a
        // long-dead source would look like it had simply never existed.
        ->and($result['health']['last_run'])->not->toBeNull();
});

it('averages duration over completed runs only', function () {
    ScraperRun::factory()->create([
        'source' => 'allevents', 'city' => 'timisoara', 'status' => 'completed',
        'started_at' => now()->subMinutes(10), 'finished_at' => now()->subMinutes(9),
    ]);
    ScraperRun::factory()->create([
        'source' => 'allevents', 'city' => 'timisoara', 'status' => 'completed',
        'started_at' => now()->subMinutes(5), 'finished_at' => now()->subMinutes(2),
    ]);
    ScraperRun::factory()->create([
        'source' => 'allevents', 'city' => 'timisoara', 'status' => 'running',
        'started_at' => now()->subMinute(), 'finished_at' => null,
    ]);

    $result = $this->stats->forSource('timisoara', 'allevents', 7);

    // (60s + 180s) / 2 — the unfinished run contributes nothing.
    expect($result['health']['avg_duration_seconds'])->toBe(120);
});

it('counts only canonical, visible events for this source and city', function () {
    Event::factory()->count(2)->create([
        'source' => 'allevents', 'city' => 'Timișoara', 'is_hidden' => false, 'merged_into_id' => null,
    ]);
    Event::factory()->create([
        'source' => 'allevents', 'city' => 'Timișoara', 'is_hidden' => true, 'merged_into_id' => null,
    ]);
    Event::factory()->create([
        'source' => 'iabilet', 'city' => 'Timișoara', 'is_hidden' => false, 'merged_into_id' => null,
    ]);

    $result = $this->stats->forSource('timisoara', 'allevents', 7);

    expect($result['health']['events_total'])->toBe(2);
});

it('keys the latest run per adapter and city pair', function () {
    ScraperRun::factory()->create([
        'source' => 'allevents', 'city' => 'timisoara',
        'status' => 'failed', 'started_at' => now()->subDays(2),
    ]);
    $newest = ScraperRun::factory()->create([
        'source' => 'allevents', 'city' => 'timisoara',
        'status' => 'completed', 'started_at' => now()->subHour(),
    ]);
    ScraperRun::factory()->create([
        'source' => 'iabilet', 'city' => 'timisoara',
        'status' => 'completed', 'started_at' => now()->subHours(5),
    ]);

    $latest = $this->stats->latestRunFor([
        ['adapter' => 'allevents', 'city' => 'timisoara'],
        ['adapter' => 'iabilet', 'city' => 'timisoara'],
        ['adapter' => 'onevent', 'city' => 'timisoara'],
    ]);

    expect($latest)->toHaveKeys(['allevents|timisoara', 'iabilet|timisoara'])
        ->and($latest['allevents|timisoara']->id)->toBe($newest->id)
        // A pair that has never run is simply absent rather than a null entry.
        ->and($latest)->not->toHaveKey('onevent|timisoara');
});

it('returns nothing for an empty pair list without touching the database', function () {
    expect($this->stats->latestRunFor([]))->toBe([]);
});
