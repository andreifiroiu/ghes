<?php

declare(strict_types=1);

namespace App\Services\Scraping\Adapters;

use App\DTOs\RawEvent;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Log;

class GenericHtmlScraper extends AbstractHtmlScraper
{
    /**
     * A configurable HTML scraper driven entirely by XPath selectors supplied in
     * the source config. Lets a new HTML source be added with config alone — no
     * new class — for sites with a simple, stable list-of-cards markup.
     *
     * Expected `selectors` keys (XPath, relative to each container except
     * `container` itself): `container`, `title`, `url` (required); `date`,
     * `venue`, `price`, `image`, `description` (optional). Attribute selectors
     * such as `.//a/@href` are supported.
     */
    public function adapterKey(): string
    {
        return 'generic_html';
    }

    public function sourceIdentifier(array $sourceConfig): string
    {
        $host = (string) parse_url($sourceConfig['url'], PHP_URL_HOST);

        return 'generic_html@'.$host;
    }

    /**
     * @param  array{adapter: string, url: string, extra_urls?: list<string>, selectors?: array<string, string>, base_url?: string, enabled: bool, interval_hours: int}  $sourceConfig
     * @param  array{label: string, timezone: string, coordinates: list<float>, radius_km: int}  $cityConfig
     * @param  callable(RawEvent): void  $onEvent
     */
    public function scrape(array $sourceConfig, array $cityConfig, callable $onEvent): void
    {
        $selectors = $sourceConfig['selectors'] ?? [];

        if (! isset($selectors['container'], $selectors['title'], $selectors['url'])) {
            Log::warning('GenericHtmlScraper: missing required selectors (container, title, url)', [
                'source' => $this->sourceIdentifier($sourceConfig),
            ]);

            return;
        }

        $urls = array_merge([$this->getUrl($sourceConfig)], $this->getExtraUrls($sourceConfig));
        $baseUrl = $this->resolveBaseUrl($sourceConfig);
        $cityLabel = $cityConfig['label'];
        $emitted = 0;

        foreach ($urls as $url) {
            $html = $this->fetchPage($url);

            if ($html === '') {
                continue;
            }

            $xpath = new DOMXPath($this->loadHtmlDocument($html));
            $containers = $xpath->query($selectors['container']);

            if ($containers === false) {
                continue;
            }

            foreach ($containers as $container) {
                if (! $container instanceof DOMElement) {
                    continue;
                }

                $event = $this->mapContainer($container, $xpath, $selectors, $baseUrl, $cityLabel);

                if ($event !== null) {
                    $onEvent($event);
                    $emitted++;
                }
            }
        }

        Log::info('GenericHtmlScraper: scrape complete', [
            'source' => $this->sourceIdentifier($sourceConfig),
            'emitted' => $emitted,
        ]);
    }

    /**
     * Map one container element to a RawEvent, or null if it lacks a title/URL.
     *
     * @param  array<string, string>  $selectors
     */
    private function mapContainer(DOMElement $container, DOMXPath $xpath, array $selectors, string $baseUrl, ?string $cityLabel): ?RawEvent
    {
        $title = $this->queryText($xpath, $selectors['title'], $container);
        $href = $this->queryText($xpath, $selectors['url'], $container);

        if ($title === null || $href === null) {
            return null;
        }

        $sourceUrl = $this->absoluteUrl($href, $baseUrl);

        $dateText = $this->optionalText($xpath, $selectors, 'date', $container);
        $startsAt = $dateText !== null
            ? $this->parseRomanianDate($dateText)?->toDateTimeString()
            : null;

        $image = $this->optionalText($xpath, $selectors, 'image', $container);
        $priceText = $this->optionalText($xpath, $selectors, 'price', $container);
        $priceMin = $priceText !== null ? $this->parsePrice($priceText) : null;

        return new RawEvent(
            title: $title,
            description: $this->optionalText($xpath, $selectors, 'description', $container),
            sourceUrl: $sourceUrl,
            sourceId: $this->extractSlug($sourceUrl),
            source: $this->adapterKey(),
            venue: $this->optionalText($xpath, $selectors, 'venue', $container),
            address: null,
            city: $cityLabel,
            startsAt: $startsAt,
            endsAt: null,
            priceMin: $priceMin,
            priceMax: null,
            currency: $priceMin !== null ? 'RON' : null,
            isFree: $priceMin !== null ? ($priceMin === 0.0) : null,
            imageUrl: $image !== null ? $this->absoluteUrl($image, $baseUrl) : null,
            metadata: [],
        );
    }

    /**
     * Run an optional selector (returns null when the key is not configured).
     *
     * @param  array<string, string>  $selectors
     */
    private function optionalText(DOMXPath $xpath, array $selectors, string $key, DOMElement $context): ?string
    {
        if (! isset($selectors[$key])) {
            return null;
        }

        return $this->queryText($xpath, $selectors[$key], $context);
    }

    /**
     * First matching node's text (works for element and attribute selectors).
     */
    private function queryText(DOMXPath $xpath, string $expression, DOMElement $context): ?string
    {
        $nodes = $xpath->query($expression, $context);

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $node = $nodes->item(0);
        $text = $node !== null ? trim((string) preg_replace('/\s+/', ' ', $node->textContent)) : '';

        return $text !== '' ? $text : null;
    }

    /**
     * Resolve the base URL for relative links: explicit `base_url`, else the
     * scheme+host of the source URL.
     *
     * @param  array{url: string, base_url?: string}  $sourceConfig
     */
    private function resolveBaseUrl(array $sourceConfig): string
    {
        if (! empty($sourceConfig['base_url'])) {
            return $sourceConfig['base_url'];
        }

        $parts = parse_url($this->getUrl($sourceConfig));

        if (isset($parts['scheme'], $parts['host'])) {
            return $parts['scheme'].'://'.$parts['host'];
        }

        return '';
    }

    /**
     * Make a possibly-relative URL absolute against the base URL.
     */
    private function absoluteUrl(string $path, string $baseUrl): string
    {
        if ($path === '' || str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if ($baseUrl === '') {
            return $path;
        }

        return rtrim($baseUrl, '/').'/'.ltrim($path, '/');
    }

    /**
     * Last path segment of a URL, used as a stable per-event source id.
     */
    private function extractSlug(string $url): ?string
    {
        $slug = basename(rtrim((string) parse_url($url, PHP_URL_PATH), '/'));

        return $slug !== '' ? $slug : null;
    }
}
