<?php

declare(strict_types=1);

use App\DTOs\RawEvent;
use App\Services\Scraping\Adapters\IaBiletScraper;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

// ---------------------------------------------------------------------------
// Test double — suppresses sleeps so tests run instantly.
// ---------------------------------------------------------------------------

class TestIaBiletScraper extends IaBiletScraper
{
    protected function sleepBetweenRequests(): void {}

    protected function sleepOnRetry(): void {}
}

// ---------------------------------------------------------------------------
// Helper: run scrape() and collect all emitted RawEvents.
// ---------------------------------------------------------------------------

/**
 * @param  array<string, mixed>  $sourceConfig
 * @param  array<string, mixed>  $cityConfig
 * @return Collection<int, RawEvent>
 */
function iaScrapeToCollection(IaBiletScraper $scraper, array $sourceConfig, array $cityConfig): Collection
{
    $events = collect();
    $scraper->scrape($sourceConfig, $cityConfig, fn ($e) => $events->push($e));

    return $events;
}

// ---------------------------------------------------------------------------
// Default config fixtures
// ---------------------------------------------------------------------------

$defaultSourceConfig = [
    'adapter' => 'iabilet',
    'url' => 'https://m.iabilet.ro/bilete-in-timisoara/',
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
// HTML fixture builders
// ---------------------------------------------------------------------------

/**
 * Build a listing page matching the real iaBilet mobile site structure.
 *
 * Each card entry may have:
 *   title    string  – event title (without city prefix)
 *   venue    string  – venue name; omit for no " // " separator
 *   category string  – category text shown in span.category > a
 *   date     string  – date string, e.g. "Sâ, 18 apr"
 *   price    string  – price string in span.price (not present on real site, but used for unit tests)
 *   href     string  – event URL (absolute or relative)
 *   img      string  – thumbnail URL
 *   hot      bool    – show low-tariff "selling fast" badge
 *   anulat   bool    – mark as past/cancelled (omits event-is-future class)
 *
 * @param  array<int, array<string, mixed>>  $cards
 */
function makeIaBiletPage(array $cards): string
{
    $items = '';

    foreach ($cards as $i => $c) {
        $href = $c['href'] ?? ('https://m.iabilet.ro/bilete-event-'.($i + 1).'/');
        $img = isset($c['img']) ? "<img src=\"{$c['img']}\" alt=\"\">" : '';

        // Cancelled/past events do NOT have event-is-future
        $futureClass = ($c['anulat'] ?? false) ? 'event-is-past' : 'event-is-future';
        $extraClass = isset($c['href']) && str_contains($c['href'], 'multi') ? 'event-multi-day' : 'event-single-day';

        $categoryHtml = isset($c['category'])
            ? "<span class=\"category color_0\"><a href=\"/bilete-category/\">{$c['category']}</a></span>"
            : '';

        // Build a.title content: optional separator and venue (spaces mirror real-site whitespace)
        $venueHtml = isset($c['venue']) ? " <span class=\"separator\">//</span> {$c['venue']}" : '';
        $titleHtml = "<a href=\"{$href}\" class=\"title\">{$c['title']}{$venueHtml}</a>";

        $dateHtml = isset($c['date']) ? "<span class=\"date pull-left\">{$c['date']}</span>" : '';

        // Price span — not present on real site but included here for unit testing price parsing
        $priceHtml = isset($c['price']) ? "<span class=\"price\">{$c['price']}</span>" : '';

        $hotHtml = ($c['hot'] ?? false)
            ? '<span class="low-tariff text-danger">Categorii care se epuizează</span>'
            : '';

        $items .= <<<HTML

            <div class="event-item col-xs-12 {$futureClass} {$extraClass}">
                <div class="image-container">
                    <a href="{$href}">{$img}</a>
                    {$categoryHtml}
                </div>
                <span class="text">
                    {$titleHtml}
                    {$dateHtml}
                    {$priceHtml}
                    {$hotHtml}
                </span>
            </div>
        HTML;
    }

    return "<html><body><div class=\"event_list container-fluid\">{$items}</div></body></html>";
}

/** An empty listing page (no event-item divs). */
function emptyIaBiletPage(): string
{
    return '<html><body><div class="event_list container-fluid"></div></body></html>';
}

// ---------------------------------------------------------------------------
// adapterKey
// ---------------------------------------------------------------------------

describe('adapterKey', function () {
    it('returns "iabilet"', function () {
        expect((new TestIaBiletScraper)->adapterKey())->toBe('iabilet');
    });
});

// ---------------------------------------------------------------------------
// sourceIdentifier
// ---------------------------------------------------------------------------

describe('sourceIdentifier', function () use ($defaultSourceConfig) {
    it('returns "iabilet@m.iabilet.ro"', function () use ($defaultSourceConfig) {
        expect((new TestIaBiletScraper)->sourceIdentifier($defaultSourceConfig))
            ->toBe('iabilet@m.iabilet.ro');
    });
});

// ---------------------------------------------------------------------------
// Title and venue splitting
// ---------------------------------------------------------------------------

describe('title and venue splitting via //', function () use ($defaultSourceConfig, $defaultCityConfig) {
    it('splits "Doru Octavian Dumitru // Sala Capitol" into title and venue', function () use ($defaultSourceConfig, $defaultCityConfig) {
        Carbon::setTestNow(Carbon::create(2026, 4, 1));

        Http::fake([
            'https://m.iabilet.ro/bilete-in-timisoara/' => Http::response(makeIaBiletPage([
                ['title' => 'Doru Octavian Dumitru', 'venue' => 'Sala Capitol',
                    'category' => 'Stand-up', 'date' => 'Sâ, 18 apr'],
            ])),
            'https://m.iabilet.ro/bilete-in-timisoara/*' => Http::response(emptyIaBiletPage()),
        ]);

        $events = iaScrapeToCollection(new TestIaBiletScraper, $defaultSourceConfig, $defaultCityConfig);

        expect($events->first()->title)->toBe('Doru Octavian Dumitru')
            ->and($events->first()->venue)->toBe('Sala Capitol');

        Carbon::setTestNow();
    });

    it('handles an event without a venue separator', function () use ($defaultSourceConfig, $defaultCityConfig) {
        Http::fake([
            'https://m.iabilet.ro/bilete-in-timisoara/' => Http::response(makeIaBiletPage([
                ['title' => 'Solo Show', 'category' => 'Stand-up', 'date' => 'Sâ, 18 apr'],
            ])),
            'https://m.iabilet.ro/bilete-in-timisoara/*' => Http::response(emptyIaBiletPage()),
        ]);

        $events = iaScrapeToCollection(new TestIaBiletScraper, $defaultSourceConfig, $defaultCityConfig);

        expect($events->first()->title)->toBe('Solo Show')
            ->and($events->first()->venue)->toBeNull();
    });

    it('strips a leading city prefix "Timisoara: " from the title', function () use ($defaultSourceConfig, $defaultCityConfig) {
        Http::fake([
            'https://m.iabilet.ro/bilete-in-timisoara/' => Http::response(makeIaBiletPage([
                ['title' => 'Timisoara: Stand-up Show', 'venue' => 'Club', 'date' => 'Sâ, 18 apr'],
            ])),
            'https://m.iabilet.ro/bilete-in-timisoara/*' => Http::response(emptyIaBiletPage()),
        ]);

        $events = iaScrapeToCollection(new TestIaBiletScraper, $defaultSourceConfig, $defaultCityConfig);

        expect($events->first()->title)->toBe('Stand-up Show');
    });

    it('does not strip city when it appears mid-title', function () use ($defaultSourceConfig, $defaultCityConfig) {
        Http::fake([
            'https://m.iabilet.ro/bilete-in-timisoara/' => Http::response(makeIaBiletPage([
                ['title' => 'FuN Timișoara: The Comeback Edition', 'date' => 'Jo, 16 apr'],
            ])),
            'https://m.iabilet.ro/bilete-in-timisoara/*' => Http::response(emptyIaBiletPage()),
        ]);

        $events = iaScrapeToCollection(new TestIaBiletScraper, $defaultSourceConfig, $defaultCityConfig);

        expect($events->first()->title)->toBe('FuN Timișoara: The Comeback Edition');
    });
});

// ---------------------------------------------------------------------------
// Category hint extraction
// ---------------------------------------------------------------------------

describe('category hint extraction', function () use ($defaultSourceConfig, $defaultCityConfig) {
    it('stores clean category text from span.category > a in metadata', function () use ($defaultSourceConfig, $defaultCityConfig) {
        Http::fake([
            'https://m.iabilet.ro/bilete-in-timisoara/' => Http::response(makeIaBiletPage([
                ['title' => 'Event', 'category' => 'Workshop', 'date' => 'Sâ, 18 apr'],
            ])),
            'https://m.iabilet.ro/bilete-in-timisoara/*' => Http::response(emptyIaBiletPage()),
        ]);

        $events = iaScrapeToCollection(new TestIaBiletScraper, $defaultSourceConfig, $defaultCityConfig);

        expect($events->first()->metadata['category_hint'])->toBe('Workshop');
    });

    it('strips city suffix when category text has old "Category City:" format', function () use ($defaultSourceConfig, $defaultCityConfig) {
        Http::fake([
            'https://m.iabilet.ro/bilete-in-timisoara/' => Http::response(makeIaBiletPage([
                ['title' => 'Event', 'category' => 'Stand-up Timisoara:', 'date' => 'Sâ, 18 apr'],
            ])),
            'https://m.iabilet.ro/bilete-in-timisoara/*' => Http::response(emptyIaBiletPage()),
        ]);

        $events = iaScrapeToCollection(new TestIaBiletScraper, $defaultSourceConfig, $defaultCityConfig);

        expect($events->first()->metadata['category_hint'])->toBe('Stand-up');
    });

    it('stores category hint in metadata, not in title', function () use ($defaultSourceConfig, $defaultCityConfig) {
        Http::fake([
            'https://m.iabilet.ro/bilete-in-timisoara/' => Http::response(makeIaBiletPage([
                ['title' => 'The Show', 'venue' => 'Arena', 'category' => 'Teatru', 'date' => 'Lu, 21 apr'],
            ])),
            'https://m.iabilet.ro/bilete-in-timisoara/*' => Http::response(emptyIaBiletPage()),
        ]);

        $events = iaScrapeToCollection(new TestIaBiletScraper, $defaultSourceConfig, $defaultCityConfig);

        expect($events->first()->title)->toBe('The Show')
            ->and($events->first()->metadata['category_hint'])->toBe('Teatru');
    });
});

// ---------------------------------------------------------------------------
// Price parsing (lei vechi → RON)
// Note: iaBilet listing pages do not show prices. These tests verify the
// parsing logic via a custom price span in the fixture.
// ---------------------------------------------------------------------------

describe('price parsing', function () use ($defaultSourceConfig, $defaultCityConfig) {
    it('divides "de la 9545 lei" by 100 to get 95.45 RON', function () use ($defaultSourceConfig, $defaultCityConfig) {
        Http::fake([
            'https://m.iabilet.ro/bilete-in-timisoara/' => Http::response(makeIaBiletPage([
                ['title' => 'Event', 'price' => 'de la 9545 lei', 'date' => 'Sâ, 18 apr'],
            ])),
            'https://m.iabilet.ro/bilete-in-timisoara/*' => Http::response(emptyIaBiletPage()),
        ]);

        $events = iaScrapeToCollection(new TestIaBiletScraper, $defaultSourceConfig, $defaultCityConfig);

        expect($events->first()->priceMin)->toBe(95.45)
            ->and($events->first()->isFree)->toBeFalse()
            ->and($events->first()->currency)->toBe('RON');
    });

    it('divides "de la 16092 lei" by 100 to get 160.92 RON', function () use ($defaultSourceConfig, $defaultCityConfig) {
        Http::fake([
            'https://m.iabilet.ro/bilete-in-timisoara/' => Http::response(makeIaBiletPage([
                ['title' => 'Expensive Show', 'price' => 'de la 16092 lei', 'date' => 'Vi, 25 apr'],
            ])),
            'https://m.iabilet.ro/bilete-in-timisoara/*' => Http::response(emptyIaBiletPage()),
        ]);

        $events = iaScrapeToCollection(new TestIaBiletScraper, $defaultSourceConfig, $defaultCityConfig);

        expect($events->first()->priceMin)->toBe(160.92);
    });

    it('parses "Gratuit" as 0.0 RON with isFree=true', function () use ($defaultSourceConfig, $defaultCityConfig) {
        Http::fake([
            'https://m.iabilet.ro/bilete-in-timisoara/' => Http::response(makeIaBiletPage([
                ['title' => 'Free Event', 'price' => 'Gratuit', 'date' => 'Du, 27 apr'],
            ])),
            'https://m.iabilet.ro/bilete-in-timisoara/*' => Http::response(emptyIaBiletPage()),
        ]);

        $events = iaScrapeToCollection(new TestIaBiletScraper, $defaultSourceConfig, $defaultCityConfig);

        expect($events->first()->priceMin)->toBe(0.0)
            ->and($events->first()->isFree)->toBeTrue();
    });

    it('sets priceMin and currency to null when no price element present', function () use ($defaultSourceConfig, $defaultCityConfig) {
        Http::fake([
            'https://m.iabilet.ro/bilete-in-timisoara/' => Http::response(makeIaBiletPage([
                ['title' => 'No Price', 'date' => 'Lu, 28 apr'],
            ])),
            'https://m.iabilet.ro/bilete-in-timisoara/*' => Http::response(emptyIaBiletPage()),
        ]);

        $events = iaScrapeToCollection(new TestIaBiletScraper, $defaultSourceConfig, $defaultCityConfig);

        expect($events->first()->priceMin)->toBeNull()
            ->and($events->first()->currency)->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// Date parsing
// ---------------------------------------------------------------------------

describe('date parsing', function () use ($defaultSourceConfig, $defaultCityConfig) {
    it('parses single date "Sâ, 18 apr" with day-of-week prefix', function () use ($defaultSourceConfig, $defaultCityConfig) {
        Carbon::setTestNow(Carbon::create(2026, 1, 1));

        Http::fake([
            'https://m.iabilet.ro/bilete-in-timisoara/' => Http::response(makeIaBiletPage([
                ['title' => 'Event', 'date' => 'Sâ, 18 apr'],
            ])),
            'https://m.iabilet.ro/bilete-in-timisoara/*' => Http::response(emptyIaBiletPage()),
        ]);

        $events = iaScrapeToCollection(new TestIaBiletScraper, $defaultSourceConfig, $defaultCityConfig);
        $startsAt = Carbon::parse($events->first()->startsAt)->setTimezone('Europe/Bucharest');

        expect($startsAt->day)->toBe(18)
            ->and($startsAt->month)->toBe(4)
            ->and($events->first()->endsAt)->toBeNull();

        Carbon::setTestNow();
    });

    it('parses same-month day range "17-19 apr" into starts_at and ends_at', function () use ($defaultSourceConfig, $defaultCityConfig) {
        Carbon::setTestNow(Carbon::create(2026, 1, 1));

        Http::fake([
            'https://m.iabilet.ro/bilete-in-timisoara/' => Http::response(makeIaBiletPage([
                ['title' => 'Festival', 'date' => '17-19 apr'],
            ])),
            'https://m.iabilet.ro/bilete-in-timisoara/*' => Http::response(emptyIaBiletPage()),
        ]);

        $events = iaScrapeToCollection(new TestIaBiletScraper, $defaultSourceConfig, $defaultCityConfig);
        $event = $events->first();
        $startsAt = Carbon::parse($event->startsAt)->setTimezone('Europe/Bucharest');
        $endsAt = Carbon::parse($event->endsAt)->setTimezone('Europe/Bucharest');

        expect($startsAt->day)->toBe(17)
            ->and($startsAt->month)->toBe(4)
            ->and($endsAt->day)->toBe(19)
            ->and($endsAt->month)->toBe(4);

        Carbon::setTestNow();
    });

    it('parses month-spanning range "29 ian - 28 mai" into starts_at and ends_at', function () use ($defaultSourceConfig, $defaultCityConfig) {
        Carbon::setTestNow(Carbon::create(2026, 1, 1));

        Http::fake([
            'https://m.iabilet.ro/bilete-in-timisoara/' => Http::response(makeIaBiletPage([
                ['title' => 'Long Run', 'date' => '29 ian - 28 mai'],
            ])),
            'https://m.iabilet.ro/bilete-in-timisoara/*' => Http::response(emptyIaBiletPage()),
        ]);

        $events = iaScrapeToCollection(new TestIaBiletScraper, $defaultSourceConfig, $defaultCityConfig);
        $event = $events->first();
        $startsAt = Carbon::parse($event->startsAt)->setTimezone('Europe/Bucharest');
        $endsAt = Carbon::parse($event->endsAt)->setTimezone('Europe/Bucharest');

        expect($startsAt->day)->toBe(29)
            ->and($startsAt->month)->toBe(1)
            ->and($endsAt->day)->toBe(28)
            ->and($endsAt->month)->toBe(5);

        Carbon::setTestNow();
    });
});

// ---------------------------------------------------------------------------
// Cancelled / past events
// ---------------------------------------------------------------------------

describe('cancelled events', function () use ($defaultSourceConfig, $defaultCityConfig) {
    it('skips a card that lacks the event-is-future class (past or cancelled)', function () use ($defaultSourceConfig, $defaultCityConfig) {
        Http::fake([
            'https://m.iabilet.ro/bilete-in-timisoara/' => Http::response(makeIaBiletPage([
                ['title' => 'Past Show', 'venue' => 'Somewhere', 'date' => 'Ma, 22 apr', 'anulat' => true],
                ['title' => 'Live Show', 'venue' => 'Arena', 'date' => 'Mi, 23 apr'],
            ])),
            'https://m.iabilet.ro/bilete-in-timisoara/*' => Http::response(emptyIaBiletPage()),
        ]);

        $events = iaScrapeToCollection(new TestIaBiletScraper, $defaultSourceConfig, $defaultCityConfig);

        expect($events)->toHaveCount(1)
            ->and($events->first()->title)->toBe('Live Show');
    });
});

// ---------------------------------------------------------------------------
// Selling-fast badge
// ---------------------------------------------------------------------------

describe('selling-fast badge', function () use ($defaultSourceConfig, $defaultCityConfig) {
    it('includes the event and sets selling_fast in metadata', function () use ($defaultSourceConfig, $defaultCityConfig) {
        Http::fake([
            'https://m.iabilet.ro/bilete-in-timisoara/' => Http::response(makeIaBiletPage([
                ['title' => 'Hot Gig', 'venue' => 'Club', 'date' => 'Vi, 25 apr', 'hot' => true],
            ])),
            'https://m.iabilet.ro/bilete-in-timisoara/*' => Http::response(emptyIaBiletPage()),
        ]);

        $events = iaScrapeToCollection(new TestIaBiletScraper, $defaultSourceConfig, $defaultCityConfig);

        expect($events->first()->title)->toBe('Hot Gig')
            ->and($events->first()->metadata['selling_fast'])->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// RawEvent core fields
// ---------------------------------------------------------------------------

describe('RawEvent fields', function () use ($defaultSourceConfig, $defaultCityConfig) {
    it('sets source to "iabilet"', function () use ($defaultSourceConfig, $defaultCityConfig) {
        Http::fake([
            'https://m.iabilet.ro/bilete-in-timisoara/' => Http::response(makeIaBiletPage([
                ['title' => 'Event', 'date' => 'Sâ, 18 apr'],
            ])),
            'https://m.iabilet.ro/bilete-in-timisoara/*' => Http::response(emptyIaBiletPage()),
        ]);

        $events = iaScrapeToCollection(new TestIaBiletScraper, $defaultSourceConfig, $defaultCityConfig);

        expect($events->first()->source)->toBe('iabilet');
    });

    it('sets city from cityConfig label', function () use ($defaultSourceConfig, $defaultCityConfig) {
        Http::fake([
            'https://m.iabilet.ro/bilete-in-timisoara/' => Http::response(makeIaBiletPage([
                ['title' => 'Event', 'date' => 'Sâ, 18 apr'],
            ])),
            'https://m.iabilet.ro/bilete-in-timisoara/*' => Http::response(emptyIaBiletPage()),
        ]);

        $events = iaScrapeToCollection(new TestIaBiletScraper, $defaultSourceConfig, $defaultCityConfig);

        expect($events->first()->city)->toBe('Timișoara');
    });

    it('extracts sourceId slug from event URL path', function () use ($defaultSourceConfig, $defaultCityConfig) {
        Http::fake([
            'https://m.iabilet.ro/bilete-in-timisoara/' => Http::response(makeIaBiletPage([
                ['title' => 'Event', 'date' => 'Sâ, 18 apr',
                    'href' => 'https://m.iabilet.ro/bilete-stand-up-doru-1234/'],
            ])),
            'https://m.iabilet.ro/bilete-in-timisoara/*' => Http::response(emptyIaBiletPage()),
        ]);

        $events = iaScrapeToCollection(new TestIaBiletScraper, $defaultSourceConfig, $defaultCityConfig);

        expect($events->first()->sourceId)->toBe('bilete-stand-up-doru-1234');
    });

    it('strips UTM query params from sourceUrl', function () use ($defaultSourceConfig, $defaultCityConfig) {
        Http::fake([
            'https://m.iabilet.ro/bilete-in-timisoara/' => Http::response(makeIaBiletPage([
                ['title' => 'Event', 'date' => 'Sâ, 18 apr',
                    'href' => '/bilete-stand-up-doru-1234/?utm_source=TownPage&utm_medium=mobile'],
            ])),
            'https://m.iabilet.ro/bilete-in-timisoara/*' => Http::response(emptyIaBiletPage()),
        ]);

        $events = iaScrapeToCollection(new TestIaBiletScraper, $defaultSourceConfig, $defaultCityConfig);

        expect($events->first()->sourceUrl)
            ->toBe('https://m.iabilet.ro/bilete-stand-up-doru-1234/');
    });

    it('sets imageUrl from img src', function () use ($defaultSourceConfig, $defaultCityConfig) {
        Http::fake([
            'https://m.iabilet.ro/bilete-in-timisoara/' => Http::response(makeIaBiletPage([
                ['title' => 'Event', 'date' => 'Sâ, 18 apr',
                    'img' => 'https://img.iabilet.ro/thumb/1234.jpg'],
            ])),
            'https://m.iabilet.ro/bilete-in-timisoara/*' => Http::response(emptyIaBiletPage()),
        ]);

        $events = iaScrapeToCollection(new TestIaBiletScraper, $defaultSourceConfig, $defaultCityConfig);

        expect($events->first()->imageUrl)->toBe('https://img.iabilet.ro/thumb/1234.jpg');
    });

    it('makes relative hrefs absolute using the base host', function () use ($defaultSourceConfig, $defaultCityConfig) {
        Http::fake([
            'https://m.iabilet.ro/bilete-in-timisoara/' => Http::response(makeIaBiletPage([
                ['title' => 'Event', 'date' => 'Sâ, 18 apr',
                    'href' => '/bilete-stand-up-doru-1234/'],
            ])),
            'https://m.iabilet.ro/bilete-in-timisoara/*' => Http::response(emptyIaBiletPage()),
        ]);

        $events = iaScrapeToCollection(new TestIaBiletScraper, $defaultSourceConfig, $defaultCityConfig);

        expect($events->first()->sourceUrl)
            ->toBe('https://m.iabilet.ro/bilete-stand-up-doru-1234/');
    });
});

// ---------------------------------------------------------------------------
// Pagination
// ---------------------------------------------------------------------------

describe('pagination', function () use ($defaultCityConfig) {
    it('fetches multiple pages and stops when a page returns no events', function () use ($defaultCityConfig) {
        $sourceConfig = [
            'adapter' => 'iabilet',
            'url' => 'https://m.iabilet.ro/bilete-in-timisoara/',
            'enabled' => true,
            'interval_hours' => 4,
        ];

        Http::fake([
            'https://m.iabilet.ro/bilete-in-timisoara/' => Http::response(makeIaBiletPage([
                ['title' => 'Event Page 1 A', 'date' => 'Sâ, 18 apr'],
                ['title' => 'Event Page 1 B', 'date' => 'Sâ, 18 apr'],
            ])),
            'https://m.iabilet.ro/bilete-in-timisoara/?page=2' => Http::response(makeIaBiletPage([
                ['title' => 'Event Page 2', 'date' => 'Su, 19 apr'],
            ])),
            'https://m.iabilet.ro/bilete-in-timisoara/?page=3' => Http::response(emptyIaBiletPage()),
        ]);

        $events = iaScrapeToCollection(new TestIaBiletScraper, $sourceConfig, $defaultCityConfig);

        expect($events)->toHaveCount(3);
    });

    it('stops after max_pages even if pages still have events', function () use ($defaultCityConfig) {
        config(['eventpulse.scrapers.max_pages' => 2]);

        $sourceConfig = [
            'adapter' => 'iabilet',
            'url' => 'https://m.iabilet.ro/bilete-in-timisoara/',
            'enabled' => true,
            'interval_hours' => 4,
        ];

        Http::fake([
            'https://m.iabilet.ro/bilete-in-timisoara/' => Http::response(makeIaBiletPage([
                ['title' => 'Event 1', 'date' => 'Sâ, 18 apr'],
            ])),
            'https://m.iabilet.ro/bilete-in-timisoara/?page=2' => Http::response(makeIaBiletPage([
                ['title' => 'Event 2', 'date' => 'Su, 19 apr'],
            ])),
            'https://m.iabilet.ro/bilete-in-timisoara/?page=3' => Http::response(makeIaBiletPage([
                ['title' => 'Event 3 — should not be fetched', 'date' => 'Lu, 20 apr'],
            ])),
        ]);

        $events = iaScrapeToCollection(new TestIaBiletScraper, $sourceConfig, $defaultCityConfig);

        expect($events)->toHaveCount(2);

        config(['eventpulse.scrapers.max_pages' => 10]);
    });

    it('stops immediately when first page returns empty HTTP response', function () use ($defaultCityConfig) {
        $sourceConfig = [
            'adapter' => 'iabilet',
            'url' => 'https://m.iabilet.ro/bilete-in-timisoara/',
            'enabled' => true,
            'interval_hours' => 4,
        ];

        Http::fake([
            'https://m.iabilet.ro/bilete-in-timisoara/' => Http::response('', 500),
        ]);

        $events = iaScrapeToCollection(new TestIaBiletScraper, $sourceConfig, $defaultCityConfig);

        expect($events)->toBeEmpty();
    });
});

// ---------------------------------------------------------------------------
// Parameterized — same class, different city URL
// ---------------------------------------------------------------------------

describe('parameterized city URL', function () use ($defaultCityConfig) {
    it('fetches from the URL in sourceConfig, not a hardcoded one', function () use ($defaultCityConfig) {
        $clujSourceConfig = [
            'adapter' => 'iabilet',
            'url' => 'https://m.iabilet.ro/bilete-in-cluj-napoca/',
            'enabled' => true,
            'interval_hours' => 4,
        ];

        $clujCityConfig = array_merge($defaultCityConfig, ['label' => 'Cluj-Napoca']);

        Http::fake([
            'https://m.iabilet.ro/bilete-in-cluj-napoca/' => Http::response(makeIaBiletPage([
                ['title' => 'Cluj Event', 'category' => 'Concert', 'date' => 'Lu, 20 apr'],
            ])),
            'https://m.iabilet.ro/bilete-in-cluj-napoca/*' => Http::response(emptyIaBiletPage()),
            // Timișoara URL should never be fetched
            'https://m.iabilet.ro/bilete-in-timisoara/*' => Http::response(makeIaBiletPage([
                ['title' => 'Wrong city', 'date' => 'Ma, 21 apr'],
            ])),
        ]);

        $events = iaScrapeToCollection(new TestIaBiletScraper, $clujSourceConfig, $clujCityConfig);

        expect($events)->toHaveCount(1)
            ->and($events->first()->title)->toBe('Cluj Event')
            ->and($events->first()->city)->toBe('Cluj-Napoca');
    });
});

// ---------------------------------------------------------------------------
// Adapter registered in config
// ---------------------------------------------------------------------------

describe('adapter registry', function () {
    it('is registered in the eventpulse adapter_registry config', function () {
        $registry = config('eventpulse.adapter_registry');

        expect($registry)->toHaveKey('iabilet')
            ->and($registry['iabilet'])->toBe(IaBiletScraper::class);
    });
});
