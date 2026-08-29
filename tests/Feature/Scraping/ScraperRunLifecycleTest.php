<?php

declare(strict_types=1);

use App\Contracts\ScraperAdapter;
use App\DTOs\RawEvent;
use App\Enums\ScraperRunStatus;
use App\Jobs\RunScraperJob;
use App\Models\ScraperRun;
use App\Services\Scraping\Adapters\ZileSiNoptiScraper;
use App\Services\Scraping\ScraperOrchestrator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Emits a few events and then throws, like a real scraper whose source changes
 * shape or drops the connection part way through a page.
 */
class PartiallyThrowingAdapter implements ScraperAdapter
{
    public function adapterKey(): string
    {
        return 'zilesinopti';
    }

    public function sourceIdentifier(array $sourceConfig): string
    {
        return 'zilesinopti@zilesinopti.ro';
    }

    public function scrape(array $sourceConfig, array $cityConfig, callable $onEvent): void
    {
        foreach (['One', 'Two', 'Three'] as $i => $title) {
            $onEvent(new RawEvent(
                title: $title,
                description: null,
                sourceUrl: "https://zilesinopti.ro/evenimente/{$i}/",
                sourceId: (string) $i,
                source: 'zilesinopti',
                venue: null,
                address: null,
                city: $cityConfig['label'],
                startsAt: null,
                endsAt: null,
                priceMin: null,
                priceMax: null,
                currency: null,
                isFree: null,
                imageUrl: null,
                metadata: [],
            ));
        }

        throw new RuntimeException('Connection reset mid-page');
    }
}

beforeEach(function () {
    // The pipeline geocodes and classifies each event; without this the suite
    // reaches for the real Nominatim and Claude endpoints.
    Http::fake();

    $this->app->bind(ZileSiNoptiScraper::class, PartiallyThrowingAdapter::class);
    $this->orchestrator = app(ScraperOrchestrator::class);
});

it('keeps the counters a failed run accumulated before it threw', function () {
    $this->orchestrator->runSource('timisoara', 'zilesinopti');

    $run = ScraperRun::where('source', 'zilesinopti')->sole();

    // The three events reached the pipeline before the adapter died. Reporting
    // zero here would read as "this source returns nothing", which is a
    // different diagnosis from "this source broke part way through".
    expect($run->status)->toBe(ScraperRunStatus::Failed)
        ->and($run->events_found)->toBe(3)
        ->and($run->events_created + $run->events_updated + $run->events_skipped)->toBe(3)
        ->and($run->error_log)->toContain('Connection reset mid-page');
});

it('casts status to the enum rather than a bare string', function () {
    $this->orchestrator->runSource('timisoara', 'zilesinopti');

    expect(ScraperRun::sole()->status)->toBeInstanceOf(ScraperRunStatus::class);
});

it('gives a run with no job behind it its own row each time', function () {
    $this->orchestrator->runSource('timisoara', 'zilesinopti');
    $this->orchestrator->runSource('timisoara', 'zilesinopti');

    // Two independent CLI invocations really are two runs.
    expect(ScraperRun::count())->toBe(2)
        ->and(ScraperRun::whereNull('job_uuid')->count())->toBe(2);
});

it('reuses one row across every attempt of the same job', function () {
    $uuid = (string) Str::uuid();

    $this->orchestrator->runSource('timisoara', 'zilesinopti', $uuid);
    $this->orchestrator->runSource('timisoara', 'zilesinopti', $uuid);
    $this->orchestrator->runSource('timisoara', 'zilesinopti', $uuid);

    // Three attempts of one dispatch are one logical run, so "runs per day"
    // counts it once instead of tripling a persistently failing source.
    expect(ScraperRun::count())->toBe(1)
        ->and(ScraperRun::sole()->job_uuid)->toBe($uuid);
});

it('resets the previous attempt state when a job is retried', function () {
    $uuid = (string) Str::uuid();

    $run = ScraperRun::factory()->create([
        'source' => 'zilesinopti',
        'city' => 'timisoara',
        'job_uuid' => $uuid,
        'status' => ScraperRunStatus::Failed,
        'events_found' => 99,
        'errors_count' => 5,
        'error_log' => ['an earlier attempt'],
        'finished_at' => now()->subHour(),
    ]);

    $this->orchestrator->runSource('timisoara', 'zilesinopti', $uuid);

    $run->refresh();

    // The retry's own outcome replaces the previous attempt's, rather than the
    // two being summed into one nonsensical row.
    expect($run->events_found)->toBe(3)
        ->and($run->errors_count)->toBe(1)
        ->and($run->error_log)->toBe(['Connection reset mid-page']);
});

it('closes out a run whose worker died before it could resolve it', function () {
    $uuid = (string) Str::uuid();

    $run = ScraperRun::factory()->create([
        'source' => 'zilesinopti',
        'city' => 'timisoara',
        'job_uuid' => $uuid,
        'status' => ScraperRunStatus::Running,
        'finished_at' => null,
    ]);

    $this->orchestrator->abandonRun($uuid, 'Job failed permanently: timed out');

    $run->refresh();

    expect($run->status)->toBe(ScraperRunStatus::Failed)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->error_log)->toContain('Job failed permanently: timed out');
});

it('leaves an already resolved run alone when the queue reports failure late', function () {
    $uuid = (string) Str::uuid();

    $run = ScraperRun::factory()->create([
        'source' => 'zilesinopti',
        'city' => 'timisoara',
        'job_uuid' => $uuid,
        'status' => ScraperRunStatus::Completed,
        'events_found' => 12,
    ]);

    $this->orchestrator->abandonRun($uuid, 'Job failed permanently: timed out');

    expect($run->refresh()->status)->toBe(ScraperRunStatus::Completed)
        ->and($run->events_found)->toBe(12);
});

it('opens a separate row when a job is delivered again while still running', function () {
    $uuid = (string) Str::uuid();

    $live = ScraperRun::factory()->create([
        'source' => 'zilesinopti',
        'city' => 'timisoara',
        'job_uuid' => $uuid,
        'status' => ScraperRunStatus::Running,
        'events_found' => 250,
        'started_at' => now()->subMinute(),
        'finished_at' => null,
    ]);

    $this->orchestrator->runSource('timisoara', 'zilesinopti', $uuid);

    // The queue re-reserving a job that has not finished is a misconfiguration
    // (retry_after below the job timeout). Reusing the row would destroy a
    // working run's counters and hand two workers the same record, so the
    // duplicate delivery gets its own row and stays visible.
    expect(ScraperRun::count())->toBe(2)
        ->and($live->refresh()->events_found)->toBe(250)
        ->and($live->status)->toBe(ScraperRunStatus::Running);
});

it('reuses a row left running by an attempt that is definitely dead', function () {
    $uuid = (string) Str::uuid();

    $dead = ScraperRun::factory()->create([
        'source' => 'zilesinopti',
        'city' => 'timisoara',
        'job_uuid' => $uuid,
        'status' => ScraperRunStatus::Running,
        'started_at' => now()->subSeconds(RunScraperJob::TIMEOUT_SECONDS + 60),
        'finished_at' => null,
    ]);

    $this->orchestrator->runSource('timisoara', 'zilesinopti', $uuid);

    // Older than the job could possibly still be running, so its worker is gone.
    expect(ScraperRun::count())->toBe(1)
        ->and($dead->refresh()->status)->toBe(ScraperRunStatus::Failed);
});

it('does not alert until the streak actually reaches the threshold', function () {
    config(['eventpulse.scraping.max_consecutive_failures' => 3]);

    ScraperRun::factory()->create([
        'source' => 'allevents', 'city' => 'timisoara',
        'status' => ScraperRunStatus::Failed, 'started_at' => now()->subHour(),
    ]);

    // One failure is one failure. `every()` on a short collection returns true,
    // which used to report a brand-new source as having failed three times.
    expect(ScraperRun::consecutiveFailuresFor('allevents', 'timisoara'))->toBe(1);
});

it('does not let an in-flight run break the failure streak', function () {
    foreach ([3, 2] as $hoursAgo) {
        ScraperRun::factory()->create([
            'source' => 'allevents', 'city' => 'timisoara',
            'status' => ScraperRunStatus::Failed, 'started_at' => now()->subHours($hoursAgo),
        ]);
    }

    ScraperRun::factory()->create([
        'source' => 'allevents', 'city' => 'timisoara',
        'status' => ScraperRunStatus::Running, 'started_at' => now()->subMinute(),
    ]);

    // A run that has not finished is not evidence of a success, so it must not
    // reset the streak — the page and the alert both read this one number.
    expect(ScraperRun::consecutiveFailuresFor('allevents', 'timisoara'))->toBe(2);
});
