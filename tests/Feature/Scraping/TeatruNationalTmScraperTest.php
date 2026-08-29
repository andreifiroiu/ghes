<?php

declare(strict_types=1);

use App\DTOs\RawEvent;
use App\Services\Scraping\Adapters\TeatruNationalTmScraper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

// ---------------------------------------------------------------------------
// Test double — suppresses HTTP delays
// ---------------------------------------------------------------------------

class TestTeatruNationalTmScraper extends TeatruNationalTmScraper
{
    protected function sleepBetweenRequests(): void {}

    protected function sleepOnRetry(): void {}
}

// ---------------------------------------------------------------------------
// HTML fixture helpers
// ---------------------------------------------------------------------------

/**
 * Build a single performance article card.
 *
 * @param  array<string, mixed>  $overrides
 */
function tnCard(array $overrides = []): string
{
    $postId = $overrides['post_id'] ?? '23202';
    $title = $overrides['title'] ?? 'ROMANIAN PSYCHO';
    $dayName = $overrides['day_name'] ?? 'Marți';
    $date = $overrides['date'] ?? '07.04';
    $time = $overrides['time'] ?? '19:00';
    $stage = $overrides['stage'] ?? 'Sala Mare';
    $ageCategory = $overrides['age_category'] ?? '15-ani';
    $ageLabel = $overrides['age_label'] ?? '+15 ani';
    $image = $overrides['image'] ?? 'https://www.tntm.ro/wp-content/uploads/2024/03/fps-scaled.jpg';
    $slug = $overrides['slug'] ?? 'romanian-psycho-12';

    return <<<HTML
    <article id="post-{$postId}" class="post_item post_format_standard post_layout_classic post_layout_classic_2 post-{$postId} post type-post status-publish format-standard has-post-thumbnail hentry">
      <div class="post_header entry-header">
        <h4 class="post_title entry-title" rel="bookmark" style="margin-bottom:-5px;font-size:20px;">{$title}</h4>
      </div>
      <span class="post_meta_item post_categories"><strong>{$dayName} {$date}</strong> / <strong>{$time}</strong> - {$stage}</span> Audiența <a href="/events/categories/{$ageCategory}">{$ageLabel}</a><br>
      <a href="/programul-lunii/{$slug}">
        <img src="{$image}" alt="{$title}" title="{$title}" class="imgsizehome">
      </a>
      <p style="line-height: 1.6rem;"></p>
      <p>
        <a class="more-link" href="/programul-lunii/{$slug}" style="margin-top:-10px;">CITEȘTE MAI MULT</a>
      </p>
    </article>
    HTML;
}

/**
 * Wrap one or more card HTML strings in a full page wrapper.
 *
 * @param  list<string>  $cards
 */
function tnPage(array $cards): string
{
    $body = implode("\n", $cards);

    return <<<HTML
    <!DOCTYPE html>
    <html><head><meta charset="utf-8"><title>Programul lunii - Teatrul Național Mihai Eminescu Timișoara</title></head>
    <body>
    <div class="page_content_wrap">
      <div class="content_wrap">
        <div class="posts_container columns_wrap">
          {$body}
        </div>
      </div>
    </div>
    </body></html>
    HTML;
}

function tnEmptyPage(): string
{
    return <<<'HTML'
    <!DOCTYPE html>
    <html><head><meta charset="utf-8"></head>
    <body>
    <div class="page_content_wrap">
      <div class="content_wrap">
        <div class="posts_container columns_wrap"></div>
      </div>
    </div>
    </body></html>
    HTML;
}

/**
 * Run a scrape and return all emitted RawEvents.
 *
 * @return Collection<int, RawEvent>
 */
function tnScrapeToCollection(
    TeatruNationalTmScraper $scraper,
    array $source = [],
    array $city = [],
): Collection {
    Http::preventStrayRequests();

    $source = array_merge([
        'adapter' => 'teatru_national_tm',
        'url' => 'https://www.tntm.ro/',
        'enabled' => true,
        'interval_hours' => 24,
    ], $source);

    $city = array_merge([
        'label' => 'Timișoara',
        'timezone' => 'Europe/Bucharest',
        'coordinates' => [45.7489, 21.2087],
        'radius_km' => 25,
    ], $city);

    $events = collect();
    $scraper->scrape($source, $city, fn (RawEvent $e) => $events->push($e));

    return $events;
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('TeatruNationalTmScraper', function (): void {

    it('returns the correct adapter key', function (): void {
        expect((new TestTeatruNationalTmScraper)->adapterKey())->toBe('teatru_national_tm');
    });

    it('returns the correct source identifier', function (): void {
        $scraper = new TestTeatruNationalTmScraper;
        expect($scraper->sourceIdentifier(['url' => 'https://www.tntm.ro/']))->toBe('teatru_national_tm@tntm.ro');
    });

    it('is registered in the adapter registry', function (): void {
        expect(config('eventpulse.adapter_registry'))->toHaveKey('teatru_national_tm');
    });

    it('maps all fields from a single performance card correctly', function (): void {
        Http::fake(['https://www.tntm.ro/*' => Http::response(tnPage([tnCard()]))]);

        $events = tnScrapeToCollection(new TestTeatruNationalTmScraper);

        expect($events)->toHaveCount(1);

        $event = $events->first();
        expect($event->title)->toBe('ROMANIAN PSYCHO');
        expect($event->source)->toBe('teatru_national_tm');
        expect($event->sourceUrl)->toBe('https://www.tntm.ro/programul-lunii/romanian-psycho-12');
        expect($event->sourceId)->toBe('romanian-psycho-12');
        expect($event->venue)->toBe('Teatrul Național Mihai Eminescu Timișoara');
        expect($event->address)->toBe('Timișoara, Str. Mărășești nr. 2');
        expect($event->city)->toBe('Timișoara');
        expect($event->imageUrl)->toBe('https://www.tntm.ro/wp-content/uploads/2024/03/fps-scaled.jpg');
        expect($event->isFree)->toBeFalse();
        expect($event->description)->toBeNull();
        expect($event->priceMin)->toBeNull();
        expect($event->endsAt)->toBeNull();
    });

    it('parses April date and time to UTC correctly', function (): void {
        // Freeze "now" before April so the year-inference resolves to 2026.
        $this->travelTo('2026-01-15');
        // 07.04 at 19:00 Europe/Bucharest (EEST = UTC+3) → UTC 16:00
        Http::fake(['https://www.tntm.ro/*' => Http::response(tnPage([tnCard([
            'date' => '07.04',
            'time' => '19:00',
        ])]))]);

        $events = tnScrapeToCollection(new TestTeatruNationalTmScraper);
        expect($events->first()->startsAt)->toBe('2026-04-07 16:00:00');
    });

    it('parses matinee time correctly', function (): void {
        // Freeze "now" before April so the year-inference resolves to 2026.
        $this->travelTo('2026-01-15');
        // 26.04 at 11:00 EEST (UTC+3) → UTC 08:00
        Http::fake(['https://www.tntm.ro/*' => Http::response(tnPage([tnCard([
            'date' => '26.04',
            'time' => '11:00',
            'slug' => 'show-matinee',
        ])]))]);

        $events = tnScrapeToCollection(new TestTeatruNationalTmScraper);
        expect($events->first()->startsAt)->toBe('2026-04-26 08:00:00');
    });

    it('extracts stage name into metadata', function (): void {
        Http::fake(['https://www.tntm.ro/*' => Http::response(tnPage([
            tnCard(['stage' => 'Studio "UTU Strugari"']),
        ]))]);

        $events = tnScrapeToCollection(new TestTeatruNationalTmScraper);
        expect($events->first()->metadata['stage'])->toBe('Studio "UTU Strugari"');
    });

    it('extracts age rating into metadata', function (): void {
        Http::fake(['https://www.tntm.ro/*' => Http::response(tnPage([
            tnCard(['age_label' => '+12 ani']),
        ]))]);

        $events = tnScrapeToCollection(new TestTeatruNationalTmScraper);
        expect($events->first()->metadata['age_rating'])->toBe('+12 ani');
    });

    it('always sets category_hint to Theatre', function (): void {
        Http::fake(['https://www.tntm.ro/*' => Http::response(tnPage([
            tnCard(['title' => 'Show A', 'slug' => 'show-a']),
            tnCard(['title' => 'Show B', 'slug' => 'show-b']),
        ]))]);

        $events = tnScrapeToCollection(new TestTeatruNationalTmScraper);

        foreach ($events as $event) {
            expect($event->metadata['category_hint'])->toBe('Theatre');
        }
    });

    it('all events share the hardcoded venue and address', function (): void {
        Http::fake(['https://www.tntm.ro/*' => Http::response(tnPage([
            tnCard(['title' => 'Show A', 'slug' => 'show-a']),
            tnCard(['title' => 'Show B', 'slug' => 'show-b']),
        ]))]);

        $events = tnScrapeToCollection(new TestTeatruNationalTmScraper);

        foreach ($events as $event) {
            expect($event->venue)->toBe('Teatrul Național Mihai Eminescu Timișoara');
            expect($event->address)->toBe('Timișoara, Str. Mărășești nr. 2');
        }
    });

    it('preserves the absolute image URL', function (): void {
        $imageUrl = 'https://www.tntm.ro/wp-content/uploads/2025/01/poster.jpg';
        Http::fake(['https://www.tntm.ro/*' => Http::response(tnPage([tnCard(['image' => $imageUrl])]))]);

        $events = tnScrapeToCollection(new TestTeatruNationalTmScraper);
        expect($events->first()->imageUrl)->toBe($imageUrl);
    });

    it('derives sourceId from the URL slug basename', function (): void {
        Http::fake(['https://www.tntm.ro/*' => Http::response(tnPage([
            tnCard(['slug' => 'in-calea-celor-vii-4']),
        ]))]);

        $events = tnScrapeToCollection(new TestTeatruNationalTmScraper);
        expect($events->first()->sourceId)->toBe('in-calea-celor-vii-4');
    });

    it('parses multiple performances correctly', function (): void {
        Http::fake(['https://www.tntm.ro/*' => Http::response(tnPage([
            tnCard(['title' => 'ROMANIAN PSYCHO', 'slug' => 'romanian-psycho-12', 'date' => '07.04']),
            tnCard(['title' => 'ÎN CALEA CELOR VII', 'slug' => 'in-calea-celor-vii-4', 'date' => '15.04']),
            tnCard(['title' => 'RICHARD AL III-LEA', 'slug' => 'richard-al-iii-lea-2', 'date' => '26.04']),
        ]))]);

        $events = tnScrapeToCollection(new TestTeatruNationalTmScraper);

        expect($events)->toHaveCount(3);
        expect($events->pluck('title')->all())->toBe(['ROMANIAN PSYCHO', 'ÎN CALEA CELOR VII', 'RICHARD AL III-LEA']);
        expect($events->pluck('sourceId')->all())->toBe(['romanian-psycho-12', 'in-calea-celor-vii-4', 'richard-al-iii-lea-2']);
    });

    it('emits 0 events when the page has no post_item articles', function (): void {
        Http::fake(['https://www.tntm.ro/*' => Http::response(tnEmptyPage())]);

        $events = tnScrapeToCollection(new TestTeatruNationalTmScraper);

        expect($events)->toHaveCount(0);
        Http::assertSentCount(1);
    });

    it('sets startsAt to null when the date cannot be parsed', function (): void {
        Http::fake(['https://www.tntm.ro/*' => Http::response(tnPage([
            tnCard(['date' => 'TBD', 'time' => '']),
        ]))]);

        $events = tnScrapeToCollection(new TestTeatruNationalTmScraper);
        expect($events->first()->startsAt)->toBeNull();
    });

    it('makes only one HTTP request per scrape (no pagination)', function (): void {
        Http::fake(['https://www.tntm.ro/*' => Http::response(tnPage([tnCard()]))]);

        tnScrapeToCollection(new TestTeatruNationalTmScraper);

        Http::assertSentCount(1);
    });

});
