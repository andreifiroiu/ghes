<?php

declare(strict_types=1);

namespace App\Services\Scraping\Adapters;

use App\DTOs\RawEvent;
use Carbon\Carbon;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Log;

class OnEventScraper extends AbstractHtmlScraper
{
    private const string SOURCE = 'onevent';

    public function adapterKey(): string
    {
        return self::SOURCE;
    }

    public function sourceIdentifier(array $sourceConfig): string
    {
        $host = parse_url($sourceConfig['url'] ?? '', PHP_URL_HOST) ?: 'onevent.ro';

        return self::SOURCE.'@'.$host;
    }

    public function scrape(array $sourceConfig, array $cityConfig, callable $onEvent): void
    {
        $url = $this->getUrl($sourceConfig);
        $cityClass = 'evo_'.$this->normalizeText($cityConfig['label']);
        $maxPages = (int) config('eventpulse.scrapers.max_pages', 10);
        $emitted = 0;

        Log::debug('OnEventScraper: starting scrape', [
            'url' => $url,
            'city_class' => $cityClass,
            'max_pages' => $maxPages,
        ]);

        for ($page = 1; $page <= $maxPages; $page++) {
            $html = $this->fetchPage($this->buildPageUrl($url, $page));

            if ($html === '') {
                break;
            }

            $cards = $this->extractEventCards($html, $cityClass);

            Log::debug('OnEventScraper: page parsed', [
                'page' => $page,
                'cards_found' => count($cards),
            ]);

            if (empty($cards)) {
                break;
            }

            foreach ($cards as $card) {
                $event = $this->parseCard($card, $cityConfig['label']);
                if ($event !== null) {
                    $onEvent($event);
                    $emitted++;
                }
            }
        }

        Log::info('OnEventScraper: scrape complete', ['emitted' => $emitted, 'url' => $url]);
    }

    /**
     * Find all eventon_list_event divs that contain a city-filtered anchor.
     *
     * @return list<DOMElement>
     */
    private function extractEventCards(string $html, string $cityClass): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $xpath = new DOMXPath($dom);

        $query = '//div[contains(concat(" ",normalize-space(@class)," ")," eventon_list_event ")]'
            .'[.//a[contains(concat(" ",normalize-space(@class)," ")," '.$cityClass.' ")]]';

        $nodes = $xpath->query($query);

        if ($nodes === false) {
            return [];
        }

        $cards = [];
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $cards[] = $node;
            }
        }

        return $cards;
    }

    /**
     * Parse a single event card DOMElement into a RawEvent DTO.
     */
    private function parseCard(DOMElement $card, string $cityLabel): ?RawEvent
    {
        $ld = $this->extractJsonLd($card);

        if ($ld === null) {
            return null;
        }

        $title = $this->decodeEntities((string) ($ld['name'] ?? ''));
        if ($title === '') {
            return null;
        }

        $sourceUrl = (string) ($ld['url'] ?? '');
        if ($sourceUrl === '') {
            return null;
        }

        $sourceId = $this->extractSourceId($sourceUrl);

        $description = null;
        $rawDesc = (string) ($ld['description'] ?? '');
        if ($rawDesc !== '') {
            $stripped = $this->stripHtml($rawDesc);
            $description = $stripped !== '' ? $stripped : null;
        }

        $startsAt = $this->parseJsonLdDate((string) ($ld['startDate'] ?? ''));
        $endsAt = $this->parseJsonLdDate((string) ($ld['endDate'] ?? ''));

        $venue = null;
        $address = null;
        $locations = $ld['location'] ?? null;
        if (is_array($locations) && isset($locations[0]) && is_array($locations[0])) {
            $loc = $locations[0];
            $venueName = $this->decodeEntities((string) ($loc['name'] ?? ''));
            $venue = $venueName !== '' ? $venueName : null;

            $street = $this->decodeEntities((string) ($loc['address']['streetAddress'] ?? ''));
            $address = $street !== '' ? $street : null;
        }

        $imageUrl = null;
        $image = $ld['image'] ?? null;
        if (is_string($image) && $image !== '') {
            $imageUrl = $image;
        } elseif (is_array($image) && isset($image['url']) && (string) $image['url'] !== '') {
            $imageUrl = (string) $image['url'];
        }

        $priceMin = null;
        $currency = null;
        $isFree = false;
        $offers = $ld['offers'] ?? null;
        if (is_array($offers) && isset($offers[0]) && is_array($offers[0])) {
            $offer = $offers[0];
            $priceValue = $offer['price'] ?? null;
            if (is_numeric($priceValue)) {
                $priceMin = (float) $priceValue;
                $isFree = $priceMin === 0.0;
            }
            $curr = (string) ($offer['priceCurrency'] ?? '');
            $currency = $curr !== '' ? $curr : null;
        }

        $categories = $this->extractCategories($card);
        $metadata = $categories !== [] ? ['categories' => $categories] : [];

        Log::debug('OnEventScraper: parsed event', [
            'title' => $title,
            'venue' => $venue,
            'starts_at' => $startsAt,
            'source_url' => $sourceUrl,
        ]);

        return new RawEvent(
            title: $title,
            description: $description,
            sourceUrl: $sourceUrl,
            sourceId: $sourceId,
            source: self::SOURCE,
            venue: $venue,
            address: $address,
            city: $cityLabel,
            startsAt: $startsAt,
            endsAt: $endsAt,
            priceMin: $priceMin,
            priceMax: null,
            currency: $currency,
            isFree: $isFree,
            imageUrl: $imageUrl,
            metadata: $metadata,
        );
    }

    /**
     * Extract and decode the JSON-LD block from within a card element.
     *
     * @return array<string, mixed>|null
     */
    private function extractJsonLd(DOMElement $card): ?array
    {
        $xpath = new DOMXPath($card->ownerDocument);
        $scripts = $xpath->query('.//script[@type="application/ld+json"]', $card);

        if ($scripts === false) {
            return null;
        }

        foreach ($scripts as $script) {
            $json = trim($script->textContent);
            if ($json === '') {
                continue;
            }

            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($json, true);

            if (is_array($decoded) && ($decoded['@type'] ?? '') === 'Event') {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Extract categories from the "Tip" evoet_eventtypes span inside the card.
     *
     * @return list<string>
     */
    private function extractCategories(DOMElement $card): array
    {
        $xpath = new DOMXPath($card->ownerDocument);

        $tipSpans = $xpath->query(
            './/span[contains(concat(" ",normalize-space(@class)," ")," ett3 ")]',
            $card,
        );

        if ($tipSpans === false) {
            return [];
        }

        $categories = [];

        foreach ($tipSpans as $span) {
            // Confirm this is the "Tip" span
            $iNodes = $xpath->query('.//em/i', $span);
            if ($iNodes === false) {
                continue;
            }

            $label = '';
            foreach ($iNodes as $i) {
                $label = trim($i->textContent);
                break;
            }

            if (mb_strtolower($label) !== 'tip') {
                continue;
            }

            $valNodes = $xpath->query('.//em[contains(@class,"evoet_dataval")]', $span);
            if ($valNodes === false) {
                continue;
            }

            foreach ($valNodes as $em) {
                if (! $em instanceof DOMElement) {
                    continue;
                }
                $val = trim($em->getAttribute('data-v'));
                if ($val !== '') {
                    $categories[] = $val;
                }
            }
        }

        return $categories;
    }

    /**
     * Normalize the non-standard Eventon date format and return a UTC datetime string.
     *
     * Input examples:
     *   "2026-4-25T08:00+3:00"   → "2026-04-25 05:00:00" (UTC)
     *   "2026-04-25T08:00:00+03:00" (already valid ISO 8601)
     */
    protected function parseJsonLdDate(?string $dateStr): ?string
    {
        if ($dateStr === null || $dateStr === '') {
            return null;
        }

        // Normalize non-padded month/day and single-digit TZ offset:
        // "2026-4-25T08:00+3:00" → "2026-04-25T08:00:00+03:00"
        $normalized = preg_replace_callback(
            '/^(\d{4})-(\d{1,2})-(\d{1,2})T(\d{2}:\d{2})([+-])(\d{1,2}):(\d{2})$/',
            fn (array $m): string => sprintf(
                '%s-%02d-%02dT%s:00%s%02d:%s',
                $m[1], (int) $m[2], (int) $m[3], $m[4], $m[5], (int) $m[6], $m[7]
            ),
            $dateStr,
        );

        try {
            return Carbon::parse($normalized ?? $dateStr)->utc()->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Extract the last path segment from the event URL as a source ID.
     */
    private function extractSourceId(string $url): ?string
    {
        $path = rtrim(parse_url($url, PHP_URL_PATH) ?? '', '/');
        $segment = basename($path);

        return $segment !== '' ? $segment : null;
    }
}
