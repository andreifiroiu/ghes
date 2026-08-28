<?php

declare(strict_types=1);

namespace App\Services\Scraping\Adapters;

use App\Contracts\ScraperAdapter;
use App\DTOs\RawEvent;
use App\Services\Processing\EventTextNormalizer;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Browsershot;

abstract class AbstractHtmlScraper implements ScraperAdapter
{
    /** @var array<int, string> */
    private const array USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:123.0) Gecko/20100101 Firefox/123.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_3_1) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.3.1 Safari/605.1.15',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36 Edg/121.0.0.0',
    ];

    /**
     * @param  array{adapter: string, url: string, extra_urls?: list<string>, enabled: bool, interval_hours: int}  $sourceConfig
     * @param  array{label: string, timezone: string, coordinates: list<float>, radius_km: int}  $cityConfig
     * @param  callable(RawEvent): void  $onEvent  Called immediately for each parsed event.
     */
    abstract public function scrape(array $sourceConfig, array $cityConfig, callable $onEvent): void;

    /**
     * Check whether the source is reachable.
     *
     * @param  array{url: string}  $sourceConfig
     */
    public function isAvailable(array $sourceConfig): bool
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => $this->randomUserAgent(),
            ])->head($sourceConfig['url']);

            return $response->status() < 400;
        } catch (\Throwable $e) {
            Log::warning("Scraper availability check failed for {$this->adapterKey()}", [
                'url' => $sourceConfig['url'],
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Return the primary URL from source config.
     *
     * @param  array{url: string}  $sourceConfig
     */
    protected function getUrl(array $sourceConfig): string
    {
        return $sourceConfig['url'];
    }

    /**
     * Return extra URLs (e.g. weekend pages) from source config.
     *
     * @param  array{extra_urls?: list<string>}  $sourceConfig
     * @return list<string>
     */
    protected function getExtraUrls(array $sourceConfig): array
    {
        return $sourceConfig['extra_urls'] ?? [];
    }

    /**
     * Fetch the HTML content of a single page.
     *
     * In local environment, responses are cached to disk for the duration
     * configured in eventpulse.scrapers.cache_ttl_minutes (default 60 min).
     * Set to 0 to disable caching. Speeds up repeated test runs without
     * hammering the live site.
     *
     * Adds a random delay in production, retries once on 429/503.
     * Returns an empty string on any failure so callers can skip gracefully.
     */
    protected function fetchPage(string $url): string
    {
        $cacheTtl = (int) config('eventpulse.scrapers.cache_ttl_minutes', 0);

        if ($cacheTtl > 0 && app()->environment('local', 'testing')) {
            $cacheKey = 'scraper_page_'.md5($url);

            /** @var string|null $cached */
            $cached = cache()->get($cacheKey);
            if ($cached !== null) {
                Log::debug('fetchPage: cache hit', ['url' => $url]);

                return $cached;
            }
        }

        $this->sleepBetweenRequests();

        try {
            $response = Http::withHeaders([
                'User-Agent' => $this->randomUserAgent(),
                'Accept-Language' => 'ro-RO,ro;q=0.9,en;q=0.8',
            ])->get($url);

            Log::debug("fetchPage: HTTP {$response->status()}", [
                'adapter' => $this->adapterKey(),
                'url' => $url,
                'body_length' => strlen($response->body()),
            ]);

            if ($response->status() === 429 || $response->status() === 503) {
                Log::warning("fetchPage: rate limited ({$response->status()}), retrying after back-off", [
                    'adapter' => $this->adapterKey(),
                    'url' => $url,
                ]);
                $this->sleepOnRetry();

                $response = Http::withHeaders([
                    'User-Agent' => $this->randomUserAgent(),
                    'Accept-Language' => 'ro-RO,ro;q=0.9,en;q=0.8',
                ])->get($url);

                Log::debug("fetchPage: retry HTTP {$response->status()}", [
                    'adapter' => $this->adapterKey(),
                    'url' => $url,
                ]);
            }

            if ($response->failed()) {
                Log::warning("Failed to fetch page for {$this->adapterKey()}", [
                    'url' => $url,
                    'status' => $response->status(),
                ]);

                return '';
            }

            $body = $response->body();

            if (isset($cacheKey) && $cacheTtl > 0) {
                cache()->put($cacheKey, $body, now()->addMinutes($cacheTtl));
                Log::debug('fetchPage: cached response', ['url' => $url, 'ttl_minutes' => $cacheTtl]);
            }

            return $body;
        } catch (\Throwable $e) {
            Log::warning("Exception fetching page for {$this->adapterKey()}", [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Fetch all pages of a paginated source up to $maxPages.
     *
     * @return Collection<int, string>
     */
    protected function fetchAllPages(string $baseUrl, int $maxPages = 10): Collection
    {
        $pages = collect();

        for ($page = 1; $page <= $maxPages; $page++) {
            $html = $this->fetchPage($this->buildPageUrl($baseUrl, $page));

            if ($html === '') {
                break;
            }

            $pages->push($html);
        }

        return $pages;
    }

    /**
     * Build the URL for a given page number. Subclasses may override for non-standard pagination.
     */
    protected function buildPageUrl(string $baseUrl, int $page): string
    {
        if ($page === 1) {
            return $baseUrl;
        }

        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        return "{$baseUrl}{$separator}page={$page}";
    }

    /**
     * The timezone of the city currently being scraped.
     *
     * Adapters that parse wall-clock times set this at the start of scrape()
     * so those times can be converted to UTC correctly.
     */
    protected ?string $cityTimezone = null;

    /**
     * Convert a parsed local wall-clock time to the UTC string the pipeline
     * stores.
     *
     * Several adapters used to serialise a naive local Carbon directly, which
     * recorded Romanian local time as if it were UTC — a 2-3 hour error that
     * pushed late-evening events onto the wrong calendar day.
     */
    protected function toUtcString(?Carbon $localWallClock): ?string
    {
        if ($localWallClock === null) {
            return null;
        }

        $timezone = $this->cityTimezone ?? (string) config('app.timezone', 'UTC');

        return Carbon::create(
            $localWallClock->year,
            $localWallClock->month,
            $localWallClock->day,
            $localWallClock->hour,
            $localWallClock->minute,
            0,
            $timezone,
        )?->utc()->toDateTimeString();
    }

    /**
     * Lowercase, strip Romanian diacritics, collapse whitespace, and trim.
     *
     * Delegates to the shared normaliser so scrapers and the deduplication
     * pipeline can never drift apart on what "the same text" means.
     */
    protected function normalizeText(string $text): string
    {
        return EventTextNormalizer::normalizeText($text);
    }

    /**
     * Parse a Romanian date string into a Carbon instance.
     *
     * Supported formats:
     *   - "4 aprilie 2026" / "18 apr"
     *   - "Sâ, 18 apr" / "Jo, 26 mar"  (day-of-week prefix, ignored)
     *   - "Vineri 03/04"               (DD/MM, current year assumed)
     *   - "03/04/2026"                 (DD/MM/YYYY)
     */
    protected function parseRomanianDate(string $text): ?Carbon
    {
        $text = trim($text);

        /** @var array<string, string> $monthMap */
        $monthMap = [
            'ianuarie' => 'January',  'ian' => 'January',
            'februarie' => 'February', 'feb' => 'February',
            'martie' => 'March',       'mar' => 'March',
            'aprilie' => 'April',      'apr' => 'April',
            'mai' => 'May',
            'iunie' => 'June',         'iun' => 'June',
            'iulie' => 'July',         'iul' => 'July',
            'august' => 'August',      'aug' => 'August',
            'septembrie' => 'September', 'sep' => 'September',
            'octombrie' => 'October',  'oct' => 'October',
            'noiembrie' => 'November', 'noi' => 'November',
            'decembrie' => 'December', 'dec' => 'December',
        ];

        // Strip optional day-of-week prefix (e.g. "Vineri ", "Jo, ", "Sâ, ")
        $text = (string) preg_replace(
            '/^(luni|mar[tț]i|miercuri|joi|vineri|s[aâ]mb[aă]t[aă]|duminic[aă]|lu|ma|mi|jo|vi|s[aâ]|du)[,.\s]+/iu',
            '',
            $text,
        );
        $text = trim($text);

        // DD/MM or DD/MM/YYYY
        if (preg_match('/^(\d{1,2})\/(\d{1,2})(?:\/(\d{4}))?$/', $text, $m)) {
            $year = isset($m[3]) ? (int) $m[3] : now()->year;

            try {
                return Carbon::createFromDate($year, (int) $m[2], (int) $m[1]);
            } catch (\Throwable) {
                return null;
            }
        }

        // DD MONTH [YYYY]  —  e.g. "4 aprilie 2026" or "18 apr"
        if (preg_match('/^(\d{1,2})\s+([a-z\x{0100}-\x{024F}]+)(?:\s+(\d{4}))?$/iu', $text, $m)) {
            $roMonth = mb_strtolower(trim($m[2]));
            $enMonth = $monthMap[$roMonth] ?? null;

            if ($enMonth === null) {
                return null;
            }

            $year = isset($m[3]) ? (int) $m[3] : now()->year;

            try {
                return Carbon::parse("{$m[1]} {$enMonth} {$year}");
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Parse a Romanian price string into a float (RON).
     *
     * Handles:
     *   - "Gratuit" / "Free"              → 0.0
     *   - "150.000 lei vechi"             → 1500.0  (÷100)
     *   - "de la 20 lei" / "de la 20 RON" → 20.0
     *   - "30 RON" / "30 lei"             → 30.0
     */
    protected function parsePrice(string $text): ?float
    {
        $trimmed = trim($text);

        if (preg_match('/^(gratuit|free)$/iu', $trimmed)) {
            return 0.0;
        }

        // "de la X lei/RON"
        if (preg_match('/de\s+la\s+([\d.,]+)\s*(?:lei|ron)/iu', $trimmed, $m)) {
            return $this->parseNumericPrice($m[1]);
        }

        // "X lei vechi"
        if (preg_match('/([\d.,]+)\s*lei\s+vechi/iu', $trimmed, $m)) {
            $value = $this->parseNumericPrice($m[1]);

            return $value !== null ? round($value / 100.0, 2) : null;
        }

        // "X RON" or "X lei"
        if (preg_match('/([\d.,]+)\s*(?:ron|lei)/iu', $trimmed, $m)) {
            return $this->parseNumericPrice($m[1]);
        }

        return null;
    }

    /**
     * Strip HTML tags, decode entities, and normalise whitespace.
     */
    protected function stripHtml(string $html): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Generate a deduplication fingerprint from normalised event fields.
     */
    protected function generateFingerprint(string $title, ?string $date, ?string $venue): string
    {
        $normalized = implode('|', [
            $this->normalizeText($title),
            $this->normalizeText($date ?? ''),
            $this->normalizeText($venue ?? ''),
        ]);

        return hash('sha256', $normalized);
    }

    /**
     * Push events from $source into $target, skipping fingerprint duplicates.
     *
     * @param  Collection<int, RawEvent>  $source
     * @param  Collection<int, RawEvent>  $target
     * @param  array<int, string>  &$seen
     */
    protected function mergeUnique(Collection $source, Collection $target, array &$seen): void
    {
        foreach ($source as $event) {
            $fp = $this->generateFingerprint($event->title, $event->startsAt, $event->venue);
            if (! in_array($fp, $seen, strict: true)) {
                $seen[] = $fp;
                $target->push($event);
            }
        }
    }

    /**
     * Pause between requests. Override in tests/subclasses to reduce delay.
     */
    protected function sleepBetweenRequests(): void
    {
        /** @var array{0: int, 1: int} $range */
        $range = config('eventpulse.scrapers.request_delay', [2, 5]);
        sleep(rand($range[0], $range[1]));
    }

    /**
     * Pause before retrying a rate-limited request. Override in tests to skip.
     */
    protected function sleepOnRetry(): void
    {
        sleep(10);
    }

    /**
     * Render a URL with a headless browser (Browsershot / Puppeteer) and return
     * the resulting page HTML.
     *
     * Override in test doubles to return fixture HTML without launching a browser.
     */
    protected function fetchWithBrowsershot(string $url): string
    {
        try {
            return Browsershot::url($url)
                ->waitUntilNetworkIdle()
                ->timeout(30)
                ->bodyHtml();
        } catch (\Throwable $e) {
            Log::warning("fetchWithBrowsershot failed for {$this->adapterKey()}", [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    private function randomUserAgent(): string
    {
        return Arr::random(self::USER_AGENTS);
    }

    /**
     * Normalise a numeric price string handling both European and US formats.
     */
    private function parseNumericPrice(string $value): ?float
    {
        // Remove dots used as thousands separators (e.g. "1.500" → "1500")
        $value = (string) preg_replace('/\.(\d{3})(?=[^\d]|$)/', '$1', $value);
        // Replace comma decimal separator with dot
        $value = str_replace(',', '.', $value);

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
