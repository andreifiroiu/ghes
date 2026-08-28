<?php

declare(strict_types=1);

use App\Contracts\ScraperAdapter;
use App\DTOs\RawEvent;
use App\Jobs\ClassifyEventJob;
use App\Models\Event;
use App\Models\EventSource;
use App\Models\ScraperRun;
use App\Services\Scraping\Adapters\IaBiletScraper;
use App\Services\Scraping\Adapters\ZileSiNoptiScraper;
use App\Services\Scraping\ScraperOrchestrator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// ---------------------------------------------------------------------------
// Fake adapter — emits a controlled list of RawEvents, no HTTP
// ---------------------------------------------------------------------------

class FakePipelineAdapter implements ScraperAdapter
{
    /** @var list<RawEvent> */
    public array $events = [];

    /** @var bool Whether scrape() should throw */
    public bool $shouldThrow = false;

    private string $key;

    public function __construct(string $key)
    {
        $this->key = $key;
    }

    public function adapterKey(): string
    {
        return $this->key;
    }

    public function sourceIdentifier(array $sourceConfig): string
    {
        return $this->key;
    }

    public function scrape(array $sourceConfig, array $cityConfig, callable $onEvent): void
    {
        if ($this->shouldThrow) {
            throw new RuntimeException("Simulated scraper failure for {$this->key}");
        }

        foreach ($this->events as $event) {
            $onEvent($event);
        }
    }
}

// ---------------------------------------------------------------------------
// Fixture helper
// ---------------------------------------------------------------------------

/**
 * @param  array<string, mixed>  $overrides
 */
function pipelineRawEvent(array $overrides = []): RawEvent
{
    return new RawEvent(
        title: $overrides['title'] ?? 'Concert Phoenix',
        description: null,
        sourceUrl: $overrides['sourceUrl'] ?? 'https://iabilet.ro/concert-phoenix/',
        sourceId: null,
        source: $overrides['source'] ?? 'iabilet',
        venue: $overrides['venue'] ?? 'Sala Capitol',
        address: null,
        city: $overrides['city'] ?? 'Timișoara',
        // array_key_exists so callers can explicitly pass null for startsAt
        startsAt: array_key_exists('startsAt', $overrides) ? $overrides['startsAt'] : '2026-05-10 19:00:00',
        endsAt: null,
        priceMin: null,
        priceMax: null,
        currency: null,
        isFree: null,
        imageUrl: null,
        metadata: [],
    );
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
});

describe('ScrapingPipeline', function (): void {

    // -----------------------------------------------------------------------
    // End-to-end deduplication
    // -----------------------------------------------------------------------

    it('merges a cross-scraper duplicate into one event with both sources attached', function (): void {
        // Same concert on two different scrapers. Different source URLs, so only
        // the shared title/city/local-date can line them up.
        $iabiletAdapter = new FakePipelineAdapter('iabilet');
        $iabiletAdapter->events = [
            pipelineRawEvent(['sourceUrl' => 'https://m.iabilet.ro/concert-phoenix/']),
        ];

        $zsnAdapter = new FakePipelineAdapter('zilesinopti');
        $zsnAdapter->events = [
            pipelineRawEvent([
                'source' => 'zilesinopti',
                'sourceUrl' => 'https://zilesinopti.ro/concert-phoenix/',
            ]),
        ];

        $this->app->instance(IaBiletScraper::class, $iabiletAdapter);
        $this->app->instance(ZileSiNoptiScraper::class, $zsnAdapter);

        $orchestrator = app(ScraperOrchestrator::class);
        $orchestrator->runSource('timisoara', 'iabilet');
        $orchestrator->runSource('timisoara', 'zilesinopti');

        $event = Event::sole();

        expect(Event::count())->toBe(1)
            ->and($event->sources_count)->toBe(2)
            ->and(EventSource::count())->toBe(2)
            ->and($event->sources()->pluck('source')->sort()->values()->all())
            ->toBe(['iabilet', 'zilesinopti']);
    });

    it('merges providers that disagree about the time of day by three hours', function (): void {
        $iabiletAdapter = new FakePipelineAdapter('iabilet');
        $iabiletAdapter->events = [
            pipelineRawEvent([
                'sourceUrl' => 'https://m.iabilet.ro/concert-phoenix/',
                'startsAt' => '2026-05-10 17:00:00',
            ]),
        ];

        $zsnAdapter = new FakePipelineAdapter('zilesinopti');
        $zsnAdapter->events = [
            pipelineRawEvent([
                'source' => 'zilesinopti',
                'sourceUrl' => 'https://zilesinopti.ro/concert-phoenix/',
                'startsAt' => '2026-05-10 20:00:00',
            ]),
        ];

        $this->app->instance(IaBiletScraper::class, $iabiletAdapter);
        $this->app->instance(ZileSiNoptiScraper::class, $zsnAdapter);

        $orchestrator = app(ScraperOrchestrator::class);
        $orchestrator->runSource('timisoara', 'iabilet');
        $orchestrator->runSource('timisoara', 'zilesinopti');

        expect(Event::count())->toBe(1);
    });

    it('merges a date-only listing with a timed one for the same local day', function (): void {
        $iabiletAdapter = new FakePipelineAdapter('iabilet');
        $iabiletAdapter->events = [
            pipelineRawEvent([
                'sourceUrl' => 'https://m.iabilet.ro/concert-phoenix/',
                // Local midnight on 2026-05-10, as iabilet publishes date-only events.
                'startsAt' => '2026-05-09 21:00:00',
            ]),
        ];

        $zsnAdapter = new FakePipelineAdapter('zilesinopti');
        $zsnAdapter->events = [
            pipelineRawEvent([
                'source' => 'zilesinopti',
                'sourceUrl' => 'https://zilesinopti.ro/concert-phoenix/',
                'startsAt' => '2026-05-10 17:00:00',
            ]),
        ];

        $this->app->instance(IaBiletScraper::class, $iabiletAdapter);
        $this->app->instance(ZileSiNoptiScraper::class, $zsnAdapter);

        $orchestrator = app(ScraperOrchestrator::class);
        $orchestrator->runSource('timisoara', 'iabilet');
        $orchestrator->runSource('timisoara', 'zilesinopti');

        // The timed listing upgrades the date-only one in place.
        expect(Event::count())->toBe(1)
            ->and(Event::sole()->starts_at->setTimezone('Europe/Bucharest')->format('H:i'))->toBe('20:00');
    });

    it('stores two distinct events from two scrapers', function (): void {
        $iabiletAdapter = new FakePipelineAdapter('iabilet');
        $iabiletAdapter->events = [
            pipelineRawEvent(['title' => 'Concert Phoenix', 'sourceUrl' => 'https://m.iabilet.ro/concert-phoenix/']),
        ];

        $zsnAdapter = new FakePipelineAdapter('zilesinopti');
        $zsnAdapter->events = [
            pipelineRawEvent([
                'title' => 'Opera Aida',
                'source' => 'zilesinopti',
                'sourceUrl' => 'https://zilesinopti.ro/opera-aida/',
            ]),
        ];

        $this->app->instance(IaBiletScraper::class, $iabiletAdapter);
        $this->app->instance(ZileSiNoptiScraper::class, $zsnAdapter);

        $orchestrator = app(ScraperOrchestrator::class);
        $orchestrator->runSource('timisoara', 'iabilet');
        $orchestrator->runSource('timisoara', 'zilesinopti');

        expect(Event::count())->toBe(2);
    });

    it('stores the event only once when the same URL is emitted twice', function (): void {
        // Same provider, same URL, same occurrence — one canonical event, one source row.
        $adapter = new FakePipelineAdapter('iabilet');
        $adapter->events = [
            pipelineRawEvent(['sourceUrl' => 'https://m.iabilet.ro/concert-phoenix/']),
            pipelineRawEvent(['sourceUrl' => 'https://m.iabilet.ro/concert-phoenix/']),
        ];

        $this->app->instance(IaBiletScraper::class, $adapter);

        app(ScraperOrchestrator::class)->runSource('timisoara', 'iabilet');

        expect(Event::count())->toBe(1)
            ->and(EventSource::count())->toBe(1);
    });

    // -----------------------------------------------------------------------
    // Multi-city isolation
    // -----------------------------------------------------------------------

    it('tags events with the correct city when running two cities', function (): void {
        config(['eventpulse.cities.testcity' => [
            'label' => 'TestCity',
            'timezone' => 'Europe/Bucharest',
            'coordinates' => [0.0, 0.0],
            'radius_km' => 25,
            'sources' => [
                ['adapter' => 'iabilet', 'url' => 'https://m.iabilet.ro/testcity/', 'enabled' => true, 'interval_hours' => 4],
            ],
        ]]);

        $tmAdapter = new FakePipelineAdapter('iabilet');
        $tmAdapter->events = [pipelineRawEvent(['city' => 'Timișoara', 'sourceUrl' => 'https://m.iabilet.ro/timisoara/'])];

        $tcAdapter = new FakePipelineAdapter('iabilet');
        $tcAdapter->events = [pipelineRawEvent(['city' => 'TestCity', 'sourceUrl' => 'https://m.iabilet.ro/testcity/'])];

        $this->app->instance(IaBiletScraper::class, $tmAdapter);
        app(ScraperOrchestrator::class)->runSource('timisoara', 'iabilet');

        $this->app->instance(IaBiletScraper::class, $tcAdapter);
        app(ScraperOrchestrator::class)->runSource('testcity', 'iabilet');

        expect(Event::where('city', 'Timișoara')->count())->toBe(1)
            ->and(Event::where('city', 'TestCity')->count())->toBe(1);
    });

    it('does not deduplicate identical events from different cities', function (): void {
        config(['eventpulse.cities.testcity' => [
            'label' => 'TestCity',
            'timezone' => 'Europe/Bucharest',
            'coordinates' => [0.0, 0.0],
            'radius_km' => 25,
            'sources' => [
                ['adapter' => 'iabilet', 'url' => 'https://m.iabilet.ro/testcity/', 'enabled' => true, 'interval_hours' => 4],
            ],
        ]]);

        // Same title + time but different cities and URLs — fuzzy dedup must NOT merge them
        $tmAdapter = new FakePipelineAdapter('iabilet');
        $tmAdapter->events = [pipelineRawEvent(['city' => 'Timișoara', 'sourceUrl' => 'https://m.iabilet.ro/concert-tm/'])];

        $tcAdapter = new FakePipelineAdapter('iabilet');
        $tcAdapter->events = [pipelineRawEvent(['city' => 'TestCity', 'sourceUrl' => 'https://m.iabilet.ro/concert-tc/'])];

        $this->app->instance(IaBiletScraper::class, $tmAdapter);
        app(ScraperOrchestrator::class)->runSource('timisoara', 'iabilet');

        $this->app->instance(IaBiletScraper::class, $tcAdapter);
        app(ScraperOrchestrator::class)->runSource('testcity', 'iabilet');

        expect(Event::count())->toBe(2);
    });

    it('creates ScraperRun records with the correct city per run', function (): void {
        config(['eventpulse.cities.testcity' => [
            'label' => 'TestCity',
            'timezone' => 'Europe/Bucharest',
            'coordinates' => [0.0, 0.0],
            'radius_km' => 25,
            'sources' => [
                ['adapter' => 'iabilet', 'url' => 'https://m.iabilet.ro/testcity/', 'enabled' => true, 'interval_hours' => 4],
            ],
        ]]);

        $adapter = new FakePipelineAdapter('iabilet');

        $this->app->instance(IaBiletScraper::class, $adapter);
        app(ScraperOrchestrator::class)->runSource('timisoara', 'iabilet');
        app(ScraperOrchestrator::class)->runSource('testcity', 'iabilet');

        expect(ScraperRun::where('city', 'timisoara')->count())->toBe(1)
            ->and(ScraperRun::where('city', 'testcity')->count())->toBe(1);
    });

    it('stores the adapter key in ScraperRun.source', function (): void {
        $adapter = new FakePipelineAdapter('iabilet');
        $this->app->instance(IaBiletScraper::class, $adapter);

        app(ScraperOrchestrator::class)->runSource('timisoara', 'iabilet');

        expect(ScraperRun::first()->source)->toBe('iabilet');
    });

    // -----------------------------------------------------------------------
    // Pipeline resilience
    // -----------------------------------------------------------------------

    it('marks ScraperRun as failed and stores no events when the adapter throws', function (): void {
        $adapter = new FakePipelineAdapter('iabilet');
        $adapter->shouldThrow = true;

        $this->app->instance(IaBiletScraper::class, $adapter);

        // Should not throw — exception is caught inside runSource()
        $saved = app(ScraperOrchestrator::class)->runSource('timisoara', 'iabilet');

        expect($saved)->toBe(0)
            ->and(Event::count())->toBe(0)
            ->and(ScraperRun::first()->status)->toBe('failed');
    });

    it('marks ScraperRun as completed with events_found=0 when the adapter emits nothing', function (): void {
        $adapter = new FakePipelineAdapter('iabilet');
        // No events set — adapter emits nothing

        $this->app->instance(IaBiletScraper::class, $adapter);

        app(ScraperOrchestrator::class)->runSource('timisoara', 'iabilet');

        $run = ScraperRun::first();
        expect($run->status)->toBe('completed')
            ->and($run->events_found)->toBe(0);
    });

    it('preserves first-adapter events when the second adapter throws', function (): void {
        $goodAdapter = new FakePipelineAdapter('iabilet');
        $goodAdapter->events = [pipelineRawEvent()];

        $badAdapter = new FakePipelineAdapter('zilesinopti');
        $badAdapter->shouldThrow = true;

        $this->app->instance(IaBiletScraper::class, $goodAdapter);
        $this->app->instance(ZileSiNoptiScraper::class, $badAdapter);

        $orchestrator = app(ScraperOrchestrator::class);
        $orchestrator->runSource('timisoara', 'iabilet');
        $orchestrator->runSource('timisoara', 'zilesinopti');

        expect(Event::count())->toBe(1)
            ->and(ScraperRun::where('source', 'iabilet')->first()->status)->toBe('completed')
            ->and(ScraperRun::where('source', 'zilesinopti')->first()->status)->toBe('failed');
    });

    it('stores an event with null startsAt without error', function (): void {
        $adapter = new FakePipelineAdapter('iabilet');
        $adapter->events = [pipelineRawEvent(['startsAt' => null])];

        $this->app->instance(IaBiletScraper::class, $adapter);

        app(ScraperOrchestrator::class)->runSource('timisoara', 'iabilet');

        expect(Event::count())->toBe(1)
            ->and(Event::first()->starts_at)->toBeNull();
    });

    it('dispatches ClassifyEventJob after creating each event', function (): void {
        $adapter = new FakePipelineAdapter('iabilet');
        $adapter->events = [pipelineRawEvent()];

        $this->app->instance(IaBiletScraper::class, $adapter);

        app(ScraperOrchestrator::class)->runSource('timisoara', 'iabilet');

        Queue::assertPushed(ClassifyEventJob::class);
    });

});
