<?php

declare(strict_types=1);

use App\DTOs\RawEvent;
use App\Services\Scraping\Adapters\ZileSiNoptiScraper;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

// ---------------------------------------------------------------------------
// Test double: suppresses sleep() calls so tests run instantly.
// ---------------------------------------------------------------------------

class TestZileSiNoptiScraper extends ZileSiNoptiScraper
{
    protected function sleepBetweenRequests(): void {}

    protected function sleepOnRetry(): void {}
}

// ---------------------------------------------------------------------------
// Helper: run scrape() and collect all emitted RawEvents into a Collection.
// ---------------------------------------------------------------------------

/**
 * @param  array<string, mixed>  $sourceConfig
 * @param  array<string, mixed>  $cityConfig
 * @return Collection<int, RawEvent>
 */
function scrapeToCollection(ZileSiNoptiScraper $scraper, array $sourceConfig, array $cityConfig): Collection
{
    $events = collect();
    $scraper->scrape($sourceConfig, $cityConfig, fn ($e) => $events->push($e));

    return $events;
}

// ---------------------------------------------------------------------------
// Default config fixtures
// ---------------------------------------------------------------------------

$defaultSourceConfig = [
    'adapter' => 'zilesinopti',
    'url' => 'https://zilesinopti.ro/evenimente-timisoara/',
    'extra_urls' => ['https://zilesinopti.ro/evenimente-timisoara-weekend/'],
    'enabled' => true,
    'interval_hours' => 4,
];

$defaultCityConfig = [
    'label' => 'Timișoara',
    'timezone' => 'Europe/Bucharest',
    'coordinates' => [45.7489, 21.2087],
    'radius_km' => 25,
];

// ---------------------------------------------------------------------------
// Fixture helpers
// ---------------------------------------------------------------------------

/**
 * Build a full listing page HTML with the kzn-sw-item structure used by zilesinopti.ro.
 *
 * @param  array<int, array{title: string, venue: string, time: string, category: string, href?: string, sumar?: string, date?: string}>  $events
 */
function makeListingPage(array $events): string
{
    $items = '';

    foreach ($events as $i => $e) {
        $n = ($i % 5) + 1;
        $href = $e['href'] ?? ('https://zilesinopti.ro/evenimente/event-'.($i + 1).'/');
        $sumar = $e['sumar'] ?? $e['title'];

        // Date embedded in the date element (weekend style) or just time (per-day style)
        $dateContent = isset($e['date'])
            ? "<div><i class='eicon-clock-o'></i>{$e['date']}\n{$e['time']}</div>"
            : "<div><i class='eicon-clock-o'></i>{$e['time']}</div>";

        $items .= <<<HTML

            <div class='kzn-sw-item'>
                <div class="kzn-sw-item-text kzn-sw-item-text-{$n}">
                    <div class="kzn-one-event-date kzn-sw-text">
                        {$dateContent}
                    </div>
                    <div class='kzn-sw-text kzn-sw-item-textsus kzn-sw-item-textsus-{$n}'>
                        {$e['category']}
                    </div>
                    <h3 class="kzn-sw-text kzn-sw-item-titlu kzn-sw-item-titlu-{$n}">
                        <a href="{$href}">{$e['title']}</a>
                    </h3>
                    <div class="kzn-sw-text kzn-sw-item-sumar kzn-sw-item-sumar-{$n}">
                        {$sumar}
                    </div>
                    <div class="kzn-sw-text kzn-sw-item-adresa kzn-sw-item-adresa-eveniment kzn-sw-item-adresa-{$n}">
                        <i class="eicon-map-pin"></i><a href="/locuri/venue/">{$e['venue']}</a>
                    </div>
                </div>
            </div>
        HTML;
    }

    return "<html><body><div class='kzn-lista-evenimente'><div class='kzn-sw-container'>{$items}</div></div></body></html>";
}

/** Minimal empty listing page */
function emptyListingPage(): string
{
    return '<html><body><div class="kzn-lista-evenimente"></div></body></html>';
}

/**
 * Fake all per-day requests (zilesinopti.ro/evenimente-timisoara/?zi=*) and the weekend URL
 * with the given HTML responses.
 *
 * @param  string  $dayHtml  Returned for per-day ?zi= URLs
 * @param  string  $weekendHtml  Returned for the weekend URL
 */
function fakeZilesRequests(string $dayHtml, string $weekendHtml = ''): void
{
    Http::fake([
        'zilesinopti.ro/evenimente-timisoara/*' => Http::response($dayHtml),
        'zilesinopti.ro/evenimente-timisoara-weekend/' => Http::response($weekendHtml ?: emptyListingPage()),
    ]);
}

// ---------------------------------------------------------------------------
// Title / venue splitting
// ---------------------------------------------------------------------------

describe('title and venue splitting via @', function () use ($defaultSourceConfig, $defaultCityConfig) {
    it('splits "Concert Jazz @ Filarmonica Banatul" into title and venue', function () use ($defaultSourceConfig, $defaultCityConfig) {
        fakeZilesRequests(makeListingPage([
            ['title' => 'Concert Jazz @ Filarmonica Banatul', 'venue' => 'Timișoara', 'time' => '19:00', 'category' => 'Concerte'],
        ]));

        $events = scrapeToCollection(new TestZileSiNoptiScraper, $defaultSourceConfig, $defaultCityConfig);

        expect($events->first()->title)->toBe('Concert Jazz')
            ->and($events->first()->venue)->toBe('Filarmonica Banatul');
    });

    it('uses title-split venue over generic "Timișoara" from venue element', function () use ($defaultSourceConfig, $defaultCityConfig) {
        fakeZilesRequests(makeListingPage([
            ['title' => 'Spectacol de dans @ Teatrul Național', 'venue' => 'Timișoara', 'time' => '20:00', 'category' => 'Teatru'],
        ]));

        $events = scrapeToCollection(new TestZileSiNoptiScraper, $defaultSourceConfig, $defaultCityConfig);

        expect($events->first()->venue)->toBe('Teatrul Național');
    });

    it('handles no @ separator — uses whole title and dedicated venue element', function () use ($defaultSourceConfig, $defaultCityConfig) {
        fakeZilesRequests(makeListingPage([
            ['title' => 'Târgul de Paște 2026', 'venue' => 'Piața Victoriei', 'time' => '10:00', 'category' => 'Târg'],
        ]));

        $events = scrapeToCollection(new TestZileSiNoptiScraper, $defaultSourceConfig, $defaultCityConfig);

        expect($events->first()->title)->toBe('Târgul de Paște 2026')
            ->and($events->first()->venue)->toBe('Piața Victoriei');
    });

    it('stores category in metadata category_hint', function () use ($defaultSourceConfig, $defaultCityConfig) {
        fakeZilesRequests(makeListingPage([
            ['title' => 'Opera Tosca @ Opera Română', 'venue' => 'Opera Română', 'time' => '19:00', 'category' => 'Opera'],
        ]));

        $events = scrapeToCollection(new TestZileSiNoptiScraper, $defaultSourceConfig, $defaultCityConfig);

        expect($events->first()->metadata['category_hint'])->toBe('Opera');
    });
});

// ---------------------------------------------------------------------------
// Romanian day-name date parsing
// ---------------------------------------------------------------------------

describe('Romanian day-name date parsing in card', function () use ($defaultSourceConfig, $defaultCityConfig) {
    it('parses inline "DUMINICĂ 05/04" from weekend card', function () use ($defaultSourceConfig, $defaultCityConfig) {
        Carbon::setTestNow(Carbon::create(2026, 4, 1));

        fakeZilesRequests(
            emptyListingPage(),
            makeListingPage([
                ['title' => 'Event @ Venue', 'venue' => 'Venue', 'time' => '20:00', 'category' => 'Party', 'date' => 'DUMINICĂ 05/04'],
            ]),
        );

        $events = scrapeToCollection(new TestZileSiNoptiScraper, $defaultSourceConfig, $defaultCityConfig);

        expect($events)->not->toBeEmpty();
        $startsAt = Carbon::parse($events->first()->startsAt)->setTimezone('Europe/Bucharest');
        expect($startsAt->day)->toBe(5)
            ->and($startsAt->month)->toBe(4)
            ->and($startsAt->hour)->toBe(20);

        Carbon::setTestNow();
    });

    it('parses inline "VINERI 10/04" from weekend card', function () use ($defaultSourceConfig, $defaultCityConfig) {
        Carbon::setTestNow(Carbon::create(2026, 4, 1));

        fakeZilesRequests(
            emptyListingPage(),
            makeListingPage([
                ['title' => 'Concert @ Club', 'venue' => 'Club', 'time' => '22:00', 'category' => 'Concerte', 'date' => 'VINERI 10/04'],
            ]),
        );

        $events = scrapeToCollection(new TestZileSiNoptiScraper, $defaultSourceConfig, $defaultCityConfig);

        $startsAt = Carbon::parse($events->first()->startsAt)->setTimezone('Europe/Bucharest');
        expect($startsAt->day)->toBe(10)
            ->and($startsAt->month)->toBe(4)
            ->and($startsAt->hour)->toBe(22);

        Carbon::setTestNow();
    });

    it('uses page date when card has only time', function () use ($defaultSourceConfig, $defaultCityConfig) {
        Carbon::setTestNow(Carbon::create(2026, 4, 6, 0, 0, 0));

        fakeZilesRequests(makeListingPage([
            ['title' => 'Fiul @ Teatrul German', 'venue' => 'Teatrul German', 'time' => '18:30', 'category' => 'Teatru'],
        ]));

        $events = scrapeToCollection(new TestZileSiNoptiScraper, $defaultSourceConfig, $defaultCityConfig);

        // The first event from the first day page uses today (April 6) as date
        $found = $events->first(fn ($e) => str_contains($e->title, 'Fiul'));
        expect($found)->not->toBeNull();
        $startsAt = Carbon::parse($found->startsAt)->setTimezone('Europe/Bucharest');
        expect($startsAt->month)->toBe(4)
            ->and($startsAt->day)->toBe(6)
            ->and($startsAt->hour)->toBe(18)
            ->and($startsAt->minute)->toBe(30);

        Carbon::setTestNow();
    });

    it('parses "SÂMBĂTĂ" day prefix correctly', function () use ($defaultSourceConfig, $defaultCityConfig) {
        Carbon::setTestNow(Carbon::create(2026, 4, 1));

        fakeZilesRequests(
            emptyListingPage(),
            makeListingPage([
                ['title' => 'Party @ Club', 'venue' => 'Club', 'time' => '23:00', 'category' => 'Party', 'date' => 'SÂMBĂTĂ 11/04'],
            ]),
        );

        $events = scrapeToCollection(new TestZileSiNoptiScraper, $defaultSourceConfig, $defaultCityConfig);

        $startsAt = Carbon::parse($events->first()->startsAt)->setTimezone('Europe/Bucharest');
        expect($startsAt->day)->toBe(11)->and($startsAt->month)->toBe(4);

        Carbon::setTestNow();
    });
});

// ---------------------------------------------------------------------------
// Deduplication
// ---------------------------------------------------------------------------

describe('deduplication between main and weekend page', function () use ($defaultSourceConfig, $defaultCityConfig) {
    it('emits the same event from both pages — DB dedup in EventPipeline handles duplicates', function () use ($defaultSourceConfig, $defaultCityConfig) {
        Carbon::setTestNow(Carbon::create(2026, 4, 5));

        $event = ['title' => 'Sunset on Sundays @ D\'Arc pe Mal', 'venue' => 'Timișoara', 'time' => '14:00', 'category' => 'Party'];

        // Per-day page for today: event with time-only (date = today = 2026-04-05)
        $dayHtml = makeListingPage([$event]);
        // Weekend page: same event with explicit inline date "DUMINICĂ 05/04" → same fingerprint
        $weekendEvent = array_merge($event, ['date' => 'DUMINICĂ 05/04']);
        $weekendHtml = makeListingPage([$weekendEvent]);

        Http::fake([
            // Only today returns the event; all other day pages are empty
            'zilesinopti.ro/evenimente-timisoara/?zi=2026-04-05' => Http::response($dayHtml),
            'zilesinopti.ro/evenimente-timisoara/*' => Http::response(emptyListingPage()),
            'zilesinopti.ro/evenimente-timisoara-weekend/' => Http::response($weekendHtml),
        ]);

        $events = scrapeToCollection(new TestZileSiNoptiScraper, $defaultSourceConfig, $defaultCityConfig);

        // The scraper emits both (day-page and weekend-page copies); EventPipeline's DB
        // fingerprint check deduplicates them when processing each event.
        $matching = $events->filter(fn ($e) => str_contains($e->title, 'Sunset on Sundays'));
        expect($matching->count())->toBe(2);

        Carbon::setTestNow();
    });

    it('does return distinct events with the same title but different dates', function () use ($defaultSourceConfig, $defaultCityConfig) {
        Carbon::setTestNow(Carbon::create(2026, 4, 5));

        // Recurring show on two different days
        Http::fake([
            'zilesinopti.ro/evenimente-timisoara/?zi=2026-04-05' => Http::response(
                makeListingPage([['title' => 'Frumoasa adormită @ Teatrul Merlin', 'venue' => 'Teatrul Merlin', 'time' => '11:00', 'category' => 'Junior']]),
            ),
            'zilesinopti.ro/evenimente-timisoara/?zi=2026-04-06' => Http::response(
                makeListingPage([['title' => 'Frumoasa adormită @ Teatrul Merlin', 'venue' => 'Teatrul Merlin', 'time' => '11:00', 'category' => 'Junior',
                    'href' => 'https://zilesinopti.ro/evenimente/frumoasa-adormita-2/']]),
            ),
            'zilesinopti.ro/evenimente-timisoara/*' => Http::response(emptyListingPage()),
            'zilesinopti.ro/evenimente-timisoara-weekend/' => Http::response(emptyListingPage()),
        ]);

        $events = scrapeToCollection(new TestZileSiNoptiScraper, $defaultSourceConfig, $defaultCityConfig);

        // Different dates → different fingerprints → both kept
        $matching = $events->filter(fn ($e) => str_contains($e->title, 'Frumoasa'));
        expect($matching->count())->toBe(2);

        Carbon::setTestNow();
    });
});

// ---------------------------------------------------------------------------
// Ad / promo block skipping
// ---------------------------------------------------------------------------

describe('skipping non-event elements', function () use ($defaultSourceConfig, $defaultCityConfig) {
    it('skips kzn-sw-item cards that have no /evenimente/ link', function () use ($defaultSourceConfig, $defaultCityConfig) {
        $adHtml = <<<'HTML'
            <html><body>
            <div class='kzn-lista-evenimente'>
                <div class='kzn-sw-item'>
                    <div class="kzn-sw-item-text kzn-sw-item-text-1">
                        <div class="kzn-one-event-date kzn-sw-text">
                            <div><i class='eicon-clock-o'></i>10:00</div>
                        </div>
                        <div class='kzn-sw-item-textsus'>Reclame</div>
                        <h3 class="kzn-sw-item-titlu">
                            <a href="https://zilesinopti.ro/stiri/o-stire/">Articol de știri</a>
                        </h3>
                    </div>
                </div>
                <div class='kzn-sw-item'>
                    <div class="kzn-sw-item-text kzn-sw-item-text-2">
                        <div class="kzn-one-event-date kzn-sw-text">
                            <div><i class='eicon-clock-o'></i>19:00</div>
                        </div>
                        <div class='kzn-sw-item-textsus'>Concert</div>
                        <h3 class="kzn-sw-item-titlu">
                            <a href="https://zilesinopti.ro/evenimente/real-concert/">Concert Real @ Sala Real</a>
                        </h3>
                    </div>
                </div>
            </div>
            </body></html>
        HTML;

        Carbon::setTestNow(Carbon::create(2026, 4, 6));

        Http::fake([
            // Only today returns the ad+event mix; all other day pages are empty
            'zilesinopti.ro/evenimente-timisoara/?zi=2026-04-06' => Http::response($adHtml),
            'zilesinopti.ro/evenimente-timisoara/*' => Http::response(emptyListingPage()),
            'zilesinopti.ro/evenimente-timisoara-weekend/' => Http::response(emptyListingPage()),
        ]);

        $events = scrapeToCollection(new TestZileSiNoptiScraper, $defaultSourceConfig, $defaultCityConfig);

        expect($events)->toHaveCount(1)
            ->and($events->first()->title)->toBe('Concert Real');

        Carbon::setTestNow();
    });

    it('skips cards with empty titles', function () use ($defaultSourceConfig, $defaultCityConfig) {
        $emptyTitleHtml = <<<'HTML'
            <html><body>
            <div class='kzn-lista-evenimente'>
                <div class='kzn-sw-item'>
                    <div class="kzn-sw-item-text kzn-sw-item-text-1">
                        <h3 class="kzn-sw-item-titlu">
                            <a href="https://zilesinopti.ro/evenimente/empty/">   </a>
                        </h3>
                    </div>
                </div>
            </div>
            </body></html>
        HTML;

        fakeZilesRequests($emptyTitleHtml);

        $events = scrapeToCollection(new TestZileSiNoptiScraper, $defaultSourceConfig, $defaultCityConfig);

        expect($events)->toBeEmpty();
    });
});

// ---------------------------------------------------------------------------
// Source metadata
// ---------------------------------------------------------------------------

describe('RawEvent fields', function () use ($defaultSourceConfig, $defaultCityConfig) {
    it('sets source to "zilesinopti"', function () use ($defaultSourceConfig, $defaultCityConfig) {
        fakeZilesRequests(makeListingPage([
            ['title' => 'Event @ Venue', 'venue' => 'Venue', 'time' => '20:00', 'category' => 'Concert'],
        ]));

        $events = scrapeToCollection(new TestZileSiNoptiScraper, $defaultSourceConfig, $defaultCityConfig);

        expect($events->first()->source)->toBe('zilesinopti');
    });

    it('sets city to Timișoara', function () use ($defaultSourceConfig, $defaultCityConfig) {
        fakeZilesRequests(makeListingPage([
            ['title' => 'Event @ Venue', 'venue' => 'Venue', 'time' => '20:00', 'category' => 'Concert'],
        ]));

        $events = scrapeToCollection(new TestZileSiNoptiScraper, $defaultSourceConfig, $defaultCityConfig);

        expect($events->first()->city)->toBe('Timișoara');
    });

    it('extracts sourceId slug from event URL', function () use ($defaultSourceConfig, $defaultCityConfig) {
        fakeZilesRequests(makeListingPage([
            ['title' => 'Event @ Venue', 'venue' => 'Venue', 'time' => '20:00', 'category' => 'Concert',
                'href' => 'https://zilesinopti.ro/evenimente/concert-jazz-filarmonica/'],
        ]));

        $events = scrapeToCollection(new TestZileSiNoptiScraper, $defaultSourceConfig, $defaultCityConfig);

        expect($events->first()->sourceId)->toBe('concert-jazz-filarmonica');
    });

    it('uses richer sumar text as description when longer than raw title', function () use ($defaultSourceConfig, $defaultCityConfig) {
        $title = 'Fiul @ Teatrul German';
        $sumar = 'Fiul @ Teatrul German. Au trecut doi ani de la divorțul dintre Pierre și Anne. Piesa explorează...';

        fakeZilesRequests(makeListingPage([
            ['title' => $title, 'venue' => 'Teatrul German', 'time' => '18:30', 'category' => 'Teatru', 'sumar' => $sumar],
        ]));

        $events = scrapeToCollection(new TestZileSiNoptiScraper, $defaultSourceConfig, $defaultCityConfig);

        expect($events->first()->description)->toBe($sumar);
    });

    it('sets description to null when sumar equals raw title', function () use ($defaultSourceConfig, $defaultCityConfig) {
        $title = 'Sunset on Sundays @ D\'Arc pe Mal';

        fakeZilesRequests(makeListingPage([
            ['title' => $title, 'venue' => 'Timișoara', 'time' => '14:00', 'category' => 'Party'],
        ]));

        $events = scrapeToCollection(new TestZileSiNoptiScraper, $defaultSourceConfig, $defaultCityConfig);

        expect($events->first()->description)->toBeNull();
    });
});
