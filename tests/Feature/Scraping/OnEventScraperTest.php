<?php

declare(strict_types=1);

use App\DTOs\RawEvent;
use App\Services\Scraping\Adapters\OnEventScraper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

// ---------------------------------------------------------------------------
// Test double — suppresses sleep calls
// ---------------------------------------------------------------------------

class TestOnEventScraper extends OnEventScraper
{
    protected function sleepBetweenRequests(): void {}

    protected function sleepOnRetry(): void {}
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Build a single Eventon event card HTML string.
 *
 * @param  array<string, mixed>  $overrides  Keys: title, url, startDate, endDate, image,
 *                                           description, venue, address, offers, categories,
 *                                           cityClass
 */
function oeCard(array $overrides = []): string
{
    $title = $overrides['title'] ?? 'Concert Test';
    $url = $overrides['url'] ?? 'https://www.onevent.ro/evenimente/concert-test/';
    $startDate = $overrides['startDate'] ?? '2026-4-25T20:00+3:00';
    $endDate = $overrides['endDate'] ?? '2026-4-25T23:00+3:00';
    $image = $overrides['image'] ?? 'https://www.onevent.ro/wp-content/uploads/concert.jpg';
    $description = $overrides['description'] ?? '<p>Concert description</p>';
    $venue = $overrides['venue'] ?? 'Club Test';
    $address = $overrides['address'] ?? 'Strada Test 1, Timișoara';
    $cityClass = $overrides['cityClass'] ?? 'evo_timisoara';

    $offersJson = '';
    if (isset($overrides['offers'])) {
        $offersJson = ',"offers":'.json_encode($overrides['offers']);
    }

    $categories = $overrides['categories'] ?? ['Muzică'];
    $categoryEms = '';
    foreach ($categories as $cat) {
        $categoryEms .= "<em data-v='{$cat}' class='evoetet_val evoet_dataval'>{$cat},</em>";
    }

    $locationJson = json_encode([
        [
            '@type' => 'Place',
            'name' => $venue,
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => $address,
            ],
        ],
    ]);

    $ldJson = json_encode([
        '@context' => 'http://schema.org',
        '@type' => 'Event',
        'name' => $title,
        'url' => $url,
        'startDate' => $startDate,
        'endDate' => $endDate,
        'image' => $image,
        'description' => $description,
        'location' => json_decode($locationJson, true),
    ]);

    // Manually inject offers into raw JSON to test both numeric and missing cases
    if ($offersJson !== '') {
        $ldJson = rtrim((string) $ldJson, '}').$offersJson.'}';
    }

    return <<<HTML
    <div id="event_1_0" class="eventon_list_event evo_eventtop scheduled event clrW">
      <div class="evo_event_schema" style="display:none">
        <script type="application/ld+json">{$ldJson}</script>
      </div>
      <a class="desc_trig {$cityClass} sin_val evcal_list_a" href="{$url}">
        <span class='evoet_eventtypes level_4 evcal_event_types ett3'>
          <em><i>Tip</i></em>
          {$categoryEms}
        </span>
      </a>
    </div>
    HTML;
}

/**
 * Wrap one or more card HTML strings in a minimal page wrapper.
 *
 * @param  list<string>  $cards
 */
function oePage(array $cards): string
{
    $body = implode("\n", $cards);

    return <<<HTML
    <!DOCTYPE html>
    <html>
    <head><meta charset="utf-8"></head>
    <body>
      <div class="eventon_events_list">
        {$body}
      </div>
    </body>
    </html>
    HTML;
}

function oeEmptyPage(): string
{
    return <<<'HTML'
    <!DOCTYPE html>
    <html><head><meta charset="utf-8"></head>
    <body><div class="eventon_events_list"></div></body>
    </html>
    HTML;
}

/**
 * Run a scrape and return collected RawEvents.
 *
 * @return Collection<int, RawEvent>
 */
function oeScrapeToCollection(
    OnEventScraper $scraper,
    array $source = [],
    array $city = [],
): Collection {
    Http::preventStrayRequests();

    $source = array_merge(['adapter' => 'onevent', 'url' => 'https://www.onevent.ro/orase/timisoara/', 'enabled' => true, 'interval_hours' => 6], $source);
    $city = array_merge(['label' => 'Timișoara', 'timezone' => 'Europe/Bucharest', 'coordinates' => [45.7489, 21.2087], 'radius_km' => 25], $city);

    $events = collect();
    $scraper->scrape($source, $city, fn (RawEvent $e) => $events->push($e));

    return $events;
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('OnEventScraper', function (): void {

    it('returns the correct adapter key', function (): void {
        $scraper = new TestOnEventScraper;
        expect($scraper->adapterKey())->toBe('onevent');
    });

    it('returns the correct source identifier', function (): void {
        $scraper = new TestOnEventScraper;
        expect($scraper->sourceIdentifier(['url' => 'https://www.onevent.ro/orase/timisoara/']))
            ->toBe('onevent@www.onevent.ro');
    });

    it('is registered in the adapter registry', function (): void {
        expect(config('eventpulse.adapter_registry'))->toHaveKey('onevent');
    });

    it('maps all fields from JSON-LD correctly', function (): void {
        Http::fake([
            'https://www.onevent.ro/*' => Http::sequence()
                ->push(oePage([oeCard()]))
                ->push(oeEmptyPage()),
        ]);

        $events = oeScrapeToCollection(new TestOnEventScraper);

        expect($events)->toHaveCount(1);

        $event = $events->first();
        expect($event->title)->toBe('Concert Test');
        expect($event->sourceUrl)->toBe('https://www.onevent.ro/evenimente/concert-test/');
        expect($event->source)->toBe('onevent');
        expect($event->venue)->toBe('Club Test');
        expect($event->address)->toBe('Strada Test 1, Timișoara');
        expect($event->imageUrl)->toBe('https://www.onevent.ro/wp-content/uploads/concert.jpg');
        expect($event->description)->toContain('Concert description');
        expect($event->city)->toBe('Timișoara');
        expect($event->startsAt)->not->toBeNull();
        expect($event->endsAt)->not->toBeNull();
    });

    it('decodes HTML entities in the title, including double-encoded sequences', function (): void {
        Http::fake([
            'https://www.onevent.ro/*' => Http::sequence()
                ->push(oePage([oeCard(['title' => 'Vinyl, Food &amp;amp; Wine', 'venue' => 'Caf&eacute; Central'])]))
                ->push(oeEmptyPage()),
        ]);

        $events = oeScrapeToCollection(new TestOnEventScraper);
        $event = $events->first();

        expect($event->title)->toBe('Vinyl, Food & Wine');
        expect($event->venue)->toBe('Café Central');
    });

    it('extracts source ID from URL path', function (): void {
        Http::fake([
            'https://www.onevent.ro/*' => Http::sequence()
                ->push(oePage([oeCard(['url' => 'https://www.onevent.ro/evenimente/concert-test/'])]))
                ->push(oeEmptyPage()),
        ]);

        $events = oeScrapeToCollection(new TestOnEventScraper);
        expect($events->first()->sourceId)->toBe('concert-test');
    });

    it('parses the non-padded JSON-LD date format correctly', function (): void {
        Http::fake([
            'https://www.onevent.ro/*' => Http::sequence()
                ->push(oePage([oeCard(['startDate' => '2026-4-5T08:00+3:00', 'endDate' => '2026-4-5T17:00+3:00'])]))
                ->push(oeEmptyPage()),
        ]);

        $events = oeScrapeToCollection(new TestOnEventScraper);
        $event = $events->first();

        expect($event->startsAt)->toBe('2026-04-05 05:00:00');
        expect($event->endsAt)->toBe('2026-04-05 14:00:00');
    });

    it('parses standard ISO 8601 date format correctly', function (): void {
        Http::fake([
            'https://www.onevent.ro/*' => Http::sequence()
                ->push(oePage([oeCard(['startDate' => '2026-04-25T20:00:00+03:00'])]))
                ->push(oeEmptyPage()),
        ]);

        $events = oeScrapeToCollection(new TestOnEventScraper);
        expect($events->first()->startsAt)->toBe('2026-04-25 17:00:00');
    });

    it('filters out events that do not have the city class on the anchor', function (): void {
        Http::fake([
            'https://www.onevent.ro/*' => Http::sequence()
                ->push(oePage([
                    oeCard(['cityClass' => 'evo_timisoara']),
                    oeCard(['title' => 'Cluj Event', 'url' => 'https://www.onevent.ro/evenimente/cluj/', 'cityClass' => 'evo_cluj']),
                ]))
                ->push(oeEmptyPage()),
        ]);

        $events = oeScrapeToCollection(new TestOnEventScraper);

        expect($events)->toHaveCount(1);
        expect($events->first()->title)->toBe('Concert Test');
    });

    it('generates the city CSS class correctly for cities with diacritics', function (): void {
        // 'Timișoara' should map to 'evo_timisoara' (diacritics stripped)
        Http::fake([
            'https://www.onevent.ro/*' => Http::sequence()
                ->push(oePage([oeCard(['cityClass' => 'evo_timisoara'])]))
                ->push(oeEmptyPage()),
        ]);

        $events = oeScrapeToCollection(new TestOnEventScraper, city: ['label' => 'Timișoara', 'timezone' => 'Europe/Bucharest', 'coordinates' => [45.7489, 21.2087], 'radius_km' => 25]);
        expect($events)->toHaveCount(1);
    });

    it('extracts a single category from the Tip span', function (): void {
        Http::fake([
            'https://www.onevent.ro/*' => Http::sequence()
                ->push(oePage([oeCard(['categories' => ['Muzică']])]))
                ->push(oeEmptyPage()),
        ]);

        $events = oeScrapeToCollection(new TestOnEventScraper);
        expect($events->first()->metadata['categories'])->toBe(['Muzică']);
    });

    it('extracts multiple categories from the Tip span', function (): void {
        Http::fake([
            'https://www.onevent.ro/*' => Http::sequence()
                ->push(oePage([oeCard(['categories' => ['Conferințe', 'Cultură', 'Educație']])]))
                ->push(oeEmptyPage()),
        ]);

        $events = oeScrapeToCollection(new TestOnEventScraper);
        expect($events->first()->metadata['categories'])->toBe(['Conferințe', 'Cultură', 'Educație']);
    });

    it('sets priceMin to null and isFree to false when offers is absent', function (): void {
        Http::fake([
            'https://www.onevent.ro/*' => Http::sequence()
                ->push(oePage([oeCard()]))  // no 'offers' key
                ->push(oeEmptyPage()),
        ]);

        $events = oeScrapeToCollection(new TestOnEventScraper);
        $event = $events->first();

        expect($event->priceMin)->toBeNull();
        expect($event->isFree)->toBeFalse();
    });

    it('sets isFree to true and priceMin to 0.0 when price is 0', function (): void {
        Http::fake([
            'https://www.onevent.ro/*' => Http::sequence()
                ->push(oePage([oeCard(['offers' => [['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'RON']]])]))
                ->push(oeEmptyPage()),
        ]);

        $events = oeScrapeToCollection(new TestOnEventScraper);
        $event = $events->first();

        expect($event->priceMin)->toBe(0.0);
        expect($event->isFree)->toBeTrue();
        expect($event->currency)->toBe('RON');
    });

    it('sets priceMin correctly and isFree to false for paid events', function (): void {
        Http::fake([
            'https://www.onevent.ro/*' => Http::sequence()
                ->push(oePage([oeCard(['offers' => [['@type' => 'Offer', 'price' => '50', 'priceCurrency' => 'RON']]])]))
                ->push(oeEmptyPage()),
        ]);

        $events = oeScrapeToCollection(new TestOnEventScraper);
        $event = $events->first();

        expect($event->priceMin)->toBe(50.0);
        expect($event->isFree)->toBeFalse();
    });

    it('stops pagination when a page returns no event cards', function (): void {
        Http::fake([
            'https://www.onevent.ro/orase/timisoara/' => Http::response(oePage([oeCard()])),
            'https://www.onevent.ro/orase/timisoara/?page=2' => Http::response(oeEmptyPage()),
        ]);

        $events = oeScrapeToCollection(new TestOnEventScraper);

        expect($events)->toHaveCount(1);
        Http::assertSentCount(2);
    });

    it('emits 0 events and makes only one request when page 1 has no events', function (): void {
        Http::fake([
            'https://www.onevent.ro/*' => Http::response(oeEmptyPage()),
        ]);

        $events = oeScrapeToCollection(new TestOnEventScraper);

        expect($events)->toHaveCount(0);
        Http::assertSentCount(1);
    });

    it('handles image as an object with a url key', function (): void {
        $imageData = ['url' => 'https://www.onevent.ro/wp-content/uploads/image.jpg', '@type' => 'ImageObject'];
        $card = oeCard(['image' => $imageData]);

        // Manually build the card with image as object
        $ldJson = json_encode([
            '@context' => 'http://schema.org',
            '@type' => 'Event',
            'name' => 'Image Test Event',
            'url' => 'https://www.onevent.ro/evenimente/image-test/',
            'startDate' => '2026-4-25T20:00+3:00',
            'image' => $imageData,
            'location' => [],
        ]);

        $cardHtml = <<<HTML
        <div class="eventon_list_event">
          <div class="evo_event_schema"><script type="application/ld+json">{$ldJson}</script></div>
          <a class="evcal_list_a evo_timisoara" href="#">
            <span class='evoet_eventtypes ett3'><em><i>Tip</i></em></span>
          </a>
        </div>
        HTML;

        Http::fake([
            'https://www.onevent.ro/*' => Http::sequence()
                ->push(oePage([$cardHtml]))
                ->push(oeEmptyPage()),
        ]);

        $events = oeScrapeToCollection(new TestOnEventScraper);
        expect($events->first()->imageUrl)->toBe('https://www.onevent.ro/wp-content/uploads/image.jpg');
    });

});
