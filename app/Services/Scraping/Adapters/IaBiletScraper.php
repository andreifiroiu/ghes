<?php

declare(strict_types=1);

namespace App\Services\Scraping\Adapters;

use App\DTOs\RawEvent;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class IaBiletScraper extends AbstractHtmlScraper
{
    private const string SOURCE = 'iabilet';

    private const string BASE_HOST = 'https://m.iabilet.ro';

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
        $baseUrl = $this->getUrl($sourceConfig);
        $city = $cityConfig['label'];
        $this->cityTimezone = $cityConfig['timezone'];
        $maxPages = (int) config('eventpulse.scrapers.max_pages', 10);
        $emitted = 0;

        Log::debug('IaBiletScraper: starting scrape', [
            'base_url' => $baseUrl,
            'city' => $city,
            'max_pages' => $maxPages,
        ]);

        for ($page = 1; $page <= $maxPages; $page++) {
            $url = $this->buildPageUrl($baseUrl, $page);

            Log::debug("IaBiletScraper: fetching page {$page}", ['url' => $url]);

            $html = $this->fetchPage($url);

            if ($html === '') {
                Log::debug("IaBiletScraper: empty HTTP response on page {$page}, stopping");
                break;
            }

            $parsed = $this->parseListingPage($html, $city);
            Log::debug("IaBiletScraper: parsed {$parsed->count()} events from page {$page}");

            if ($parsed->isEmpty()) {
                Log::debug("IaBiletScraper: no events on page {$page}, stopping pagination");
                break;
            }

            foreach ($parsed as $event) {
                $onEvent($event);
                $emitted++;
            }
        }

        Log::info('IaBiletScraper: scrape complete', ['emitted' => $emitted, 'city' => $city]);
    }

    /**
     * Parse all `.event-item` elements from a listing page.
     *
     * @return Collection<int, RawEvent>
     */
    private function parseListingPage(string $html, string $city): Collection
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $cards = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " event-item ")]');

        $events = collect();

        if ($cards === false) {
            return $events;
        }

        $cardCount = $cards->length;
        Log::debug("IaBiletScraper: found {$cardCount} event-item elements in page");

        foreach ($cards as $card) {
            $event = $this->parseEventCard($card, $xpath, $city);
            if ($event !== null) {
                $events->push($event);
            }
        }

        return $events;
    }

    private function parseEventCard(\DOMNode $card, \DOMXPath $xpath, string $city): ?RawEvent
    {
        if ($this->isCardCancelled($card, $xpath)) {
            Log::debug('IaBiletScraper: skipping cancelled/past event card');

            return null;
        }

        // Title anchor contains both href and title+venue text
        $titleAnchor = $xpath->query(
            './/a[contains(concat(" ", normalize-space(@class), " "), " title ")]',
            $card,
        )->item(0);

        if (! $titleAnchor instanceof \DOMElement) {
            return null;
        }

        $href = $titleAnchor->getAttribute('href');
        if ($href === '') {
            return null;
        }

        // Strip UTM query params from URL
        $cleanHref = strtok($href, '?');
        $sourceUrl = str_starts_with($cleanHref, 'http') ? $cleanHref : self::BASE_HOST.$cleanHref;

        // Thumbnail image
        $imgEl = $xpath->query('.//img', $card)->item(0);
        $imageUrl = $imgEl instanceof \DOMElement ? ($imgEl->getAttribute('src') ?: null) : null;

        // Category: "span.category > a"
        $catEl = $xpath->query(
            './/*[contains(concat(" ", normalize-space(@class), " "), " category ")]//a',
            $card,
        )->item(0);
        $catText = $catEl ? trim($catEl->textContent) : '';
        $categoryHint = $catText !== '' ? $this->extractCategoryHint($catText, $city) : null;

        // Title + Venue from a.title text content
        $titleText = $this->stripHtml($titleAnchor->textContent);
        if ($titleText === '') {
            return null;
        }

        [$rawTitle, $venue] = $this->splitTitleVenue($titleText);
        $title = $this->stripCityPrefix($rawTitle, $city);

        if ($title === '') {
            return null;
        }

        // Date
        $dateEl = $xpath->query(
            './/*[contains(concat(" ", normalize-space(@class), " "), " date ")]',
            $card,
        )->item(0);
        $dateText = $dateEl ? trim($dateEl->textContent) : null;
        [$startsAt, $endsAt] = $this->parseIaBiletDate($dateText);

        // Price (optional — not shown on listing page, but present in test fixtures)
        $priceEl = $xpath->query(
            './/*[contains(concat(" ", normalize-space(@class), " "), " price ")]',
            $card,
        )->item(0);
        $priceText = $priceEl ? trim($priceEl->textContent) : null;
        [$priceMin, $isFree] = $this->parseIaBiletPrice($priceText);

        // Selling-fast badge
        $hotEl = $xpath->query(
            './/*[contains(concat(" ", normalize-space(@class), " "), " low-tariff ")]',
            $card,
        )->item(0);
        $sellingFast = $hotEl !== null;

        $slug = basename(rtrim((string) parse_url($sourceUrl, PHP_URL_PATH), '/'));

        Log::debug('IaBiletScraper: parsed card', [
            'title' => $title,
            'venue' => $venue,
            'starts_at' => $startsAt?->toDateString(),
            'ends_at' => $endsAt?->toDateString(),
            'price_min' => $priceMin,
            'source_url' => $sourceUrl,
        ]);

        return new RawEvent(
            title: $title,
            description: null,
            sourceUrl: $sourceUrl,
            sourceId: $slug,
            source: $this->adapterKey(),
            venue: $venue,
            address: null,
            city: $city,
            startsAt: $this->toUtcString($startsAt),
            endsAt: $this->toUtcString($endsAt),
            priceMin: $priceMin,
            priceMax: null,
            currency: $priceMin !== null ? 'RON' : null,
            isFree: $isFree,
            imageUrl: $imageUrl,
            metadata: array_filter([
                'category_hint' => $categoryHint,
                'selling_fast' => $sellingFast ?: null,
            ]),
        );
    }

    /**
     * Return true if the card should be skipped.
     *
     * Skips past events (no `event-is-future` class) and cancelled events
     * (class contains "anulat"/"cancelled" or a badge says "Anulat").
     */
    private function isCardCancelled(\DOMNode $card, \DOMXPath $xpath): bool
    {
        if ($card instanceof \DOMElement) {
            $class = $card->getAttribute('class');

            // Only process future events
            if (! str_contains($class, 'event-is-future')) {
                return true;
            }

            $classLower = mb_strtolower($class);
            if (str_contains($classLower, 'anulat') || str_contains($classLower, 'cancelled')) {
                return true;
            }
        }

        $badges = $xpath->query(
            './/*[contains(@class,"badge") or contains(@class,"status") or contains(@class,"label")]',
            $card,
        );

        if ($badges !== false) {
            foreach ($badges as $badge) {
                if (mb_stripos(trim($badge->textContent), 'anulat') !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Strip a leading "City: " prefix from a title if present.
     *
     * "Timisoara: Stand-up Show" → "Stand-up Show"
     * "FuN Timișoara: The Show" → "FuN Timișoara: The Show" (not at start)
     */
    private function stripCityPrefix(string $title, string $city): string
    {
        $normalizedCity = $this->normalizeText($city);
        $normalizedTitle = $this->normalizeText($title);
        $prefix = $normalizedCity.': ';

        if (str_starts_with($normalizedTitle, $prefix)) {
            return trim(mb_substr($title, mb_strlen($prefix)));
        }

        return $title;
    }

    /**
     * Extract the category hint from a "Category CityLabel:" prefix string,
     * or return the category text as-is when no city suffix is present.
     *
     * "Stand-up Timisoara:" → "Stand-up"
     * "Workshop"            → "Workshop"
     */
    private function extractCategoryHint(string $catText, string $city): string
    {
        $stripped = rtrim(trim($catText), ':');
        $normalizedCity = $this->normalizeText($city);
        $normalizedStripped = $this->normalizeText($stripped);

        $pos = mb_strrpos($normalizedStripped, ' '.$normalizedCity);
        if ($pos !== false) {
            return trim(mb_substr($stripped, 0, $pos));
        }

        return $stripped;
    }

    /**
     * Split "Title // Venue" on the " // " separator.
     *
     * @return array{0: string, 1: string|null}
     */
    private function splitTitleVenue(string $text): array
    {
        $parts = explode(' // ', $text, 2);

        return [trim($parts[0]), isset($parts[1]) ? trim($parts[1]) : null];
    }

    /**
     * Parse iaBilet date text into a (startsAt, endsAt) pair.
     *
     * Supported formats:
     *   "Sâ, 18 apr"      → single date (day-of-week prefix handled by parseRomanianDate)
     *   "17-19 apr"        → day range, same month → (17 apr, 19 apr)
     *   "29 ian - 28 mai"  → month-spanning range  → (29 ian, 28 mai)
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function parseIaBiletDate(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return [null, null];
        }

        $text = trim($text);

        // Day range within same month: "17-19 apr"
        if (preg_match('/^(\d{1,2})-(\d{1,2})\s+([a-z\x{0100}-\x{024F}]+)$/iu', $text, $m)) {
            return [
                $this->parseRomanianDate("{$m[1]} {$m[3]}"),
                $this->parseRomanianDate("{$m[2]} {$m[3]}"),
            ];
        }

        // Month-spanning range: "29 ian - 28 mai"
        if (preg_match(
            '/^(\d{1,2}\s+[a-z\x{0100}-\x{024F}]+)\s*[-–]\s*(\d{1,2}\s+[a-z\x{0100}-\x{024F}]+)$/iu',
            $text,
            $m,
        )) {
            return [
                $this->parseRomanianDate(trim($m[1])),
                $this->parseRomanianDate(trim($m[2])),
            ];
        }

        // Single date (possibly with day-of-week prefix)
        return [$this->parseRomanianDate($text), null];
    }

    /**
     * Parse an iaBilet price string into (priceMin, isFree).
     *
     * iaBilet prices are in "lei vechi" format and must be divided by 100 to get RON:
     *   "de la 9545 lei"  → 95.45 RON
     *   "de la 16092 lei" → 160.92 RON
     *   "Gratuit"         → 0.00 RON (free)
     *
     * @return array{0: ?float, 1: ?bool}
     */
    private function parseIaBiletPrice(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return [null, null];
        }

        $trimmed = trim($text);

        if (preg_match('/^(gratuit|free)$/iu', $trimmed)) {
            return [0.0, true];
        }

        if (preg_match('/de\s+la\s+([\d.,]+)\s*lei/iu', $trimmed, $m)) {
            $raw = $this->parseLeiBrutiToRon($m[1]);
            if ($raw !== null) {
                return [$raw, false];
            }
        }

        return [null, null];
    }

    /**
     * Convert a raw iaBilet price string (Romanian number format, lei vechi) to RON.
     *
     * "9545" → 95.45 (÷100)
     * "16.092" → 160.92 (dot = thousands separator in Romanian, then ÷100)
     */
    private function parseLeiBrutiToRon(string $value): ?float
    {
        // Strip dot-thousands separators: "16.092" → "16092"
        $value = (string) preg_replace('/\.(\d{3})(?=[^\d]|$)/', '$1', $value);
        // Replace comma decimal separator with dot
        $value = str_replace(',', '.', $value);

        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value / 100.0, 2);
    }
}
