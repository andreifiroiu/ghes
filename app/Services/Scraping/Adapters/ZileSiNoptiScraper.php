<?php

declare(strict_types=1);

namespace App\Services\Scraping\Adapters;

use App\DTOs\RawEvent;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ZileSiNoptiScraper extends AbstractHtmlScraper
{
    private const string SOURCE = 'zilesinopti';

    /**
     * How many upcoming days to scrape from the main calendar.
     * Reads from config; defaults to 7.
     */
    private const int DEFAULT_DAYS = 7;

    /**
     * Maximum detail-page fetches per 60-second window, to avoid hammering the server.
     */
    private const int DETAIL_RATE_LIMIT = 5;

    public function adapterKey(): string
    {
        return self::SOURCE;
    }

    public function sourceIdentifier(array $sourceConfig): string
    {
        $host = (string) parse_url($sourceConfig['url'], PHP_URL_HOST);

        return self::SOURCE.'@'.$host;
    }

    public function scrape(array $sourceConfig, array $cityConfig, callable $onEvent): void
    {
        $mainUrl = $this->getUrl($sourceConfig);
        $weekendUrl = $this->getExtraUrls($sourceConfig)[0] ?? null;
        $city = $cityConfig['label'];
        $this->cityTimezone = $cityConfig['timezone'] ?? null;

        // Shared detail-page fetch state — prevents fetching the same URL
        // more than once across all listing pages in a single scrape run.
        /** @var array<string, string|null> $fetchedDescriptions  URL → description (or null if not found) */
        $fetchedDescriptions = [];
        $detailCount = 0;
        $detailWindowStart = time();

        $days = (int) config('eventpulse.scrapers.max_pages', self::DEFAULT_DAYS);
        $emitted = 0;

        Log::debug('ZileSiNoptiScraper: starting scrape', [
            'main_url' => $mainUrl,
            'weekend_url' => $weekendUrl,
            'city' => $city,
            'days' => $days,
        ]);

        // Fetch one page per upcoming calendar day
        for ($offset = 0; $offset < $days; $offset++) {
            $date = now()->addDays($offset);
            $url = rtrim($mainUrl, '/').'/?zi='.$date->format('Y-m-d');

            Log::debug("ZileSiNoptiScraper: fetching day page {$offset}/{$days}", ['url' => $url]);

            $html = $this->fetchPage($url);
            if ($html === '') {
                Log::debug('ZileSiNoptiScraper: empty response, skipping day', ['url' => $url]);

                continue;
            }

            $parsed = $this->parseListingPage($html, $date->startOfDay()->copy(), $city, $fetchedDescriptions, $detailCount, $detailWindowStart);
            Log::debug("ZileSiNoptiScraper: parsed {$parsed->count()} events from day page", ['date' => $date->toDateString()]);

            foreach ($parsed as $event) {
                $onEvent($event);
                $emitted++;
            }
        }

        // Weekend page — inline dates embedded in each card
        if ($weekendUrl !== null) {
            Log::debug('ZileSiNoptiScraper: fetching weekend page', ['url' => $weekendUrl]);

            $weekendHtml = $this->fetchPage($weekendUrl);
            if ($weekendHtml !== '') {
                $parsed = $this->parseListingPage($weekendHtml, null, $city, $fetchedDescriptions, $detailCount, $detailWindowStart);
                Log::debug("ZileSiNoptiScraper: parsed {$parsed->count()} events from weekend page");

                foreach ($parsed as $event) {
                    $onEvent($event);
                    $emitted++;
                }
            } else {
                Log::debug('ZileSiNoptiScraper: empty response from weekend page');
            }
        }

        Log::info('ZileSiNoptiScraper: scrape complete', ['emitted' => $emitted, 'city' => $city]);
    }

    /**
     * Parse all `.kzn-sw-item` cards from a listing page.
     *
     * @param  ?Carbon  $pageDate  Known date for this page (null = each card carries its own date).
     * @param  array<string, string|null>  $fetchedDescriptions  URL → description (or null if fetch attempted but empty)
     * @return Collection<int, RawEvent>
     */
    private function parseListingPage(
        string $html,
        ?Carbon $pageDate,
        string $city,
        array &$fetchedDescriptions,
        int &$detailCount,
        int &$detailWindowStart,
    ): Collection {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $items = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " kzn-sw-item ")]');
        $itemCount = $items ? $items->length : 0;

        Log::debug("ZileSiNoptiScraper: found {$itemCount} kzn-sw-item elements in page");

        $events = collect();

        foreach ($items as $item) {
            $event = $this->parseEventCard($item, $xpath, $pageDate, $city);
            if ($event === null) {
                continue;
            }

            Log::debug('ZileSiNoptiScraper: parsed card', [
                'title' => $event->title,
                'starts_at' => $event->startsAt,
                'venue' => $event->venue,
                'source_url' => $event->sourceUrl,
            ]);

            // Optionally enrich with description from detail page
            if ($event->description === null) {
                if (array_key_exists($event->sourceUrl, $fetchedDescriptions)) {
                    // Already fetched this URL in this scrape run — reuse cached result
                    $cached = $fetchedDescriptions[$event->sourceUrl];
                    if ($cached !== null) {
                        $event = $this->withDescription($event, $cached);
                        Log::debug('ZileSiNoptiScraper: reused cached description', ['url' => $event->sourceUrl]);
                    }
                } else {
                    // Reset rate-limit window
                    if (time() - $detailWindowStart >= 60) {
                        $detailCount = 0;
                        $detailWindowStart = time();
                    }

                    if ($detailCount < self::DETAIL_RATE_LIMIT) {
                        Log::debug("ZileSiNoptiScraper: fetching detail page ({$detailCount}/".self::DETAIL_RATE_LIMIT.')', [
                            'url' => $event->sourceUrl,
                        ]);
                        $description = $this->fetchDetailDescription($event->sourceUrl);
                        $fetchedDescriptions[$event->sourceUrl] = $description;
                        if ($description !== null) {
                            $event = $this->withDescription($event, $description);
                            Log::debug('ZileSiNoptiScraper: enriched event with description');
                        }
                        $detailCount++;
                    }
                }
            }

            $events->push($event);
        }

        return $events;
    }

    private function parseEventCard(\DOMNode $item, \DOMXPath $xpath, ?Carbon $pageDate, string $city): ?RawEvent
    {
        // Title anchor — must link to /evenimente/
        $anchor = $xpath->query('.//*[contains(@class,"kzn-sw-item-titlu")]//a[@href]', $item)->item(0);
        if (! $anchor instanceof \DOMElement) {
            return null;
        }

        $sourceUrl = $anchor->getAttribute('href');
        if (! str_contains($sourceUrl, '/evenimente/')) {
            return null;
        }

        $rawTitle = $this->stripHtml($anchor->textContent);
        if ($rawTitle === '') {
            return null;
        }

        [$title, $venueFromTitle] = $this->splitTitleVenue($rawTitle);

        // Dedicated venue element (more specific than the title split when available)
        $venueEl = $xpath->query('.//*[contains(@class,"kzn-sw-item-adresa")]//a', $item)->item(0);
        $venue = $venueEl ? $this->stripHtml($venueEl->textContent) : null;

        // Prefer title-split venue when the dedicated element just says the city name
        if ($venueFromTitle !== null && (
            $venue === null || mb_strtolower($venue) === mb_strtolower($city)
        )) {
            $venue = $venueFromTitle;
        }

        // Category badge
        $catEl = $xpath->query('.//*[contains(@class,"kzn-sw-item-textsus")]', $item)->item(0);
        $category = $catEl ? trim($catEl->textContent) : null;

        // Date + time
        $dateEl = $xpath->query('.//*[contains(@class,"kzn-one-event-date")]', $item)->item(0);
        $startsAt = $dateEl
            ? $this->parseDateTimeFromCard(trim($dateEl->textContent), $pageDate)
            : null;

        // Description from summary element (only if richer than the raw title)
        $sumarEl = $xpath->query('.//*[contains(@class,"kzn-sw-item-sumar")]', $item)->item(0);
        $sumar = $sumarEl ? $this->stripHtml($sumarEl->textContent) : null;
        $description = ($sumar !== null && $sumar !== $rawTitle && mb_strlen($sumar) > mb_strlen($rawTitle) + 5)
            ? $sumar
            : null;

        // Image via CSS background-image on the lazy-loaded anchor
        $imgEl = $xpath->query('.//*[contains(@class,"kzn-sw-item-imagine")]//a', $item)->item(0);
        $imageUrl = null;
        if ($imgEl instanceof \DOMElement) {
            $style = $imgEl->getAttribute('style');
            if (preg_match('/background-image\s*:\s*url\([\'"]?([^\'"\)]+)[\'"]?\)/i', $style, $m)) {
                $imageUrl = $m[1];
            }
        }

        $slug = basename(rtrim($sourceUrl, '/'));

        return new RawEvent(
            title: $title,
            description: $description,
            sourceUrl: $sourceUrl,
            sourceId: $slug,
            source: $this->adapterKey(),
            venue: $venue,
            address: null,
            city: $city,
            startsAt: $this->toUtcString($startsAt),
            endsAt: null,
            priceMin: null,
            priceMax: null,
            currency: null,
            isFree: null,
            imageUrl: $imageUrl,
            metadata: array_filter(['category_hint' => $category]),
        );
    }

    /**
     * Parse the text content of `.kzn-one-event-date` into a Carbon datetime.
     *
     * Supported formats from the live site:
     *   - "10:00"                    → time only, date = $pageDate
     *   - "DUMINICĂ 05/04\n10:00"   → inline Romanian day+date + time
     */
    private function parseDateTimeFromCard(string $text, ?Carbon $pageDate): ?Carbon
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        // Split on newlines produced by the icon + text layout
        $lines = array_values(
            array_filter(array_map('trim', preg_split('/[\n\r]+/', $text) ?: [])),
        );

        Log::debug('ZileSiNoptiScraper: parseDateTimeFromCard', [
            'raw' => json_encode($text),
            'lines' => $lines,
            'page_date' => $pageDate?->toDateString(),
        ]);

        // The live site sometimes concatenates day+date+time without a newline, e.g.
        // "Duminică 05/0415:00" — split on the time portion (HH:MM at end of string).
        // Require at least one non-digit char first so pure times like "18:00" are not split.
        if (count($lines) === 1 && preg_match('/^(\D.+?)(\d{1,2}:\d{2})$/', $lines[0], $m)) {
            $lines = [trim($m[1]), $m[2]];
        }

        if (count($lines) >= 2) {
            // e.g. ["DUMINICĂ 05/04", "10:00"]
            $date = $this->parseRomanianDate($lines[0]);
            $timeStr = $lines[1];
        } else {
            // e.g. ["19:00"] — date comes from the page URL
            $date = $pageDate ? $pageDate->copy() : null;
            $timeStr = $lines[0] ?? '';
        }

        if ($date === null) {
            return null;
        }

        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($timeStr), $m)) {
            $date->setTime((int) $m[1], (int) $m[2], 0);
        }

        return $date;
    }

    /**
     * Split "Event Title @ Venue Name" on the first " @ " separator.
     *
     * @return array{0: string, 1: string|null}
     */
    private function splitTitleVenue(string $raw): array
    {
        $parts = explode(' @ ', $raw, 2);

        return [trim($parts[0]), isset($parts[1]) ? trim($parts[1]) : null];
    }

    /**
     * Fetch the event detail page and extract the first meaningful paragraphs as description.
     */
    private function fetchDetailDescription(string $url): ?string
    {
        $html = $this->fetchPage($url);
        if ($html === '') {
            return null;
        }

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        // Prefer the WordPress entry content area; fall back to generic paragraphs
        $paragraphs = $xpath->query(
            '//div[contains(@class,"entry-content")]//p | //div[contains(@class,"elementor-widget-container")]//p[not(ancestor::*[contains(@class,"kzn-")])]',
        );

        $parts = [];
        foreach ($paragraphs as $p) {
            $text = $this->stripHtml($p->textContent);
            if (mb_strlen($text) > 30 && ! $this->isBoilerplate($text)) {
                $parts[] = $text;
            }

            if (count($parts) >= 3) {
                break;
            }
        }

        return $parts !== [] ? implode("\n\n", $parts) : null;
    }

    /**
     * Detect site-wide boilerplate text that should never be stored as a description.
     * These strings appear in the footer/sidebar of every zilesinopti.ro page.
     */
    private function isBoilerplate(string $text): bool
    {
        $markers = [
            'marcă înregistrată',
            'City Guide Media',
            'Vrei să fii la curent cu cele mai noi Evenimente',
            'ZILE ȘI NOPȚI',
            'zilesinopti.ro',
        ];

        foreach ($markers as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return a new RawEvent with the given description (readonly DTO workaround).
     */
    private function withDescription(RawEvent $event, string $description): RawEvent
    {
        return new RawEvent(
            title: $event->title,
            description: $description,
            sourceUrl: $event->sourceUrl,
            sourceId: $event->sourceId,
            source: $event->source,
            venue: $event->venue,
            address: $event->address,
            city: $event->city,
            startsAt: $event->startsAt,
            endsAt: $event->endsAt,
            priceMin: $event->priceMin,
            priceMax: $event->priceMax,
            currency: $event->currency,
            isFree: $event->isFree,
            imageUrl: $event->imageUrl,
            metadata: $event->metadata,
        );
    }
}
