<?php

declare(strict_types=1);

use App\DTOs\RawEvent;
use App\Services\Scraping\Adapters\GenericHtmlScraper;
use Illuminate\Support\Facades\Http;

class TestGenericHtmlScraper extends GenericHtmlScraper
{
    protected function sleepBetweenRequests(): void {}

    protected function sleepOnRetry(): void {}
}

/**
 * @param  array<string, mixed>  $sourceConfig
 * @return list<RawEvent>
 */
function genericScrape(array $sourceConfig): array
{
    $cityConfig = [
        'label' => 'Timișoara',
        'timezone' => 'Europe/Bucharest',
        'coordinates' => [45.7489, 21.2087],
        'radius_km' => 25,
    ];

    $events = [];
    (new TestGenericHtmlScraper)->scrape($sourceConfig, $cityConfig, function (RawEvent $event) use (&$events): void {
        $events[] = $event;
    });

    return $events;
}

it('extracts events using configured xpath selectors', function () {
    Http::fake([
        'example.com/*' => Http::response('<html><body>'
            .'<div class="event"><h2>Jazz Night</h2><a href="/events/jazz-night">link</a>'
            .'<time>18 aprilie 2026</time><span class="venue">Club X</span>'
            .'<span class="price">30 lei</span><p>Great jazz.</p></div>'
            .'<div class="event"><h2>Rock Show</h2><a href="https://example.com/events/rock">link</a></div>'
            .'</body></html>'),
    ]);

    $events = genericScrape([
        'adapter' => 'generic_html',
        'url' => 'https://example.com/events',
        'selectors' => [
            'container' => '//div[@class="event"]',
            'title' => './/h2',
            'url' => './/a/@href',
            'date' => './/time',
            'venue' => './/span[@class="venue"]',
            'price' => './/span[@class="price"]',
            'description' => './/p',
        ],
        'enabled' => true,
        'interval_hours' => 6,
    ]);

    expect($events)->toHaveCount(2);

    expect($events[0]->title)->toBe('Jazz Night')
        ->and($events[0]->sourceUrl)->toBe('https://example.com/events/jazz-night')
        ->and($events[0]->venue)->toBe('Club X')
        ->and($events[0]->priceMin)->toBe(30.0)
        ->and($events[0]->currency)->toBe('RON')
        ->and($events[0]->city)->toBe('Timișoara')
        ->and($events[0]->source)->toBe('generic_html')
        ->and($events[0]->startsAt)->toContain('2026-04-18');

    // Absolute URL is preserved as-is.
    expect($events[1]->title)->toBe('Rock Show')
        ->and($events[1]->sourceUrl)->toBe('https://example.com/events/rock');
});

it('returns nothing when required selectors are missing', function () {
    Http::fake(['*' => Http::response('<html></html>')]);

    $events = genericScrape([
        'adapter' => 'generic_html',
        'url' => 'https://example.com/events',
        'selectors' => ['container' => '//div'], // no title/url
        'enabled' => true,
        'interval_hours' => 6,
    ]);

    expect($events)->toBe([]);
    Http::assertNothingSent();
});

it('skips containers missing a title or url', function () {
    Http::fake([
        'example.com/*' => Http::response('<html><body>'
            .'<div class="event"><span>no title here</span></div>'
            .'</body></html>'),
    ]);

    $events = genericScrape([
        'adapter' => 'generic_html',
        'url' => 'https://example.com/events',
        'selectors' => [
            'container' => '//div[@class="event"]',
            'title' => './/h2',
            'url' => './/a/@href',
        ],
        'enabled' => true,
        'interval_hours' => 6,
    ]);

    expect($events)->toBe([]);
});
