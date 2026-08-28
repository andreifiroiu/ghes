<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\Models\Event;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Facades\Log;

class EventEnricher
{
    /**
     * Enriches events with geocoding data and additional metadata.
     *
     * Calls external geocoding APIs (Nominatim or Google) to resolve
     * addresses into latitude/longitude coordinates, and optionally
     * fetches extra metadata from the event's source URL.
     */
    public function __construct(
        private readonly HttpClient $http,
    ) {}

    /**
     * Geocode an event's address to populate latitude, longitude, and neighborhood.
     *
     * Uses the configured geocoding provider (Nominatim or Google) to convert
     * the event's address/venue/city into geographic coordinates. Skips events
     * that are already geocoded. On any failure (or no result) the event is
     * still marked geocoded with null coordinates to avoid retry loops —
     * coordinates are an enhancement, not a hard requirement.
     */
    public function enrichGeocoding(Event $event): Event
    {
        if ($event->is_geocoded) {
            return $event;
        }

        $query = $this->buildQuery($event);

        if ($query === '') {
            $event->update(['is_geocoded' => true]);

            return $event;
        }

        try {
            $result = $this->geocode($query);

            $event->update([
                'latitude' => $result['latitude'] ?? null,
                'longitude' => $result['longitude'] ?? null,
                'neighborhood' => $result['neighborhood'] ?? $event->neighborhood,
                'is_geocoded' => true,
            ]);
        } catch (\Throwable $e) {
            Log::warning('EventEnricher: geocoding failed', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);

            // Mark geocoded with null coords so the pipeline does not retry endlessly.
            $event->update(['is_geocoded' => true]);
        }

        return $event;
    }

    /**
     * Build a geocoding query from the most specific location fields available.
     */
    private function buildQuery(Event $event): string
    {
        $parts = array_filter([
            $event->address ?: $event->venue,
            $event->city,
        ]);

        return implode(', ', $parts);
    }

    /**
     * Resolve a query string into coordinates and (optionally) a neighborhood
     * using the configured provider.
     *
     * @return array{latitude: float, longitude: float, neighborhood: string|null}|array{}
     */
    private function geocode(string $query): array
    {
        $provider = (string) config('eventpulse.geocoding.provider', 'nominatim');
        $timeout = (int) config('eventpulse.geocoding.timeout_seconds', 10);

        return $provider === 'google'
            ? $this->geocodeWithGoogle($query, $timeout)
            : $this->geocodeWithNominatim($query, $timeout);
    }

    /**
     * @return array{latitude: float, longitude: float, neighborhood: string|null}|array{}
     */
    private function geocodeWithNominatim(string $query, int $timeout): array
    {
        $response = $this->http
            ->withHeaders(['User-Agent' => (string) config('eventpulse.geocoding.user_agent')])
            ->timeout($timeout)
            ->get((string) config('eventpulse.geocoding.nominatim_url'), [
                'q' => $query,
                'format' => 'json',
                'limit' => 1,
                'addressdetails' => 1,
            ]);

        $data = $response->json();

        if (! is_array($data) || ! isset($data[0]['lat'], $data[0]['lon'])) {
            return [];
        }

        /** @var array<string, mixed> $address */
        $address = is_array($data[0]['address'] ?? null) ? $data[0]['address'] : [];

        return [
            'latitude' => (float) $data[0]['lat'],
            'longitude' => (float) $data[0]['lon'],
            'neighborhood' => $this->firstString($address, ['neighbourhood', 'suburb', 'city_district', 'quarter']),
        ];
    }

    /**
     * @return array{latitude: float, longitude: float, neighborhood: string|null}|array{}
     */
    private function geocodeWithGoogle(string $query, int $timeout): array
    {
        $response = $this->http
            ->timeout($timeout)
            ->get('https://maps.googleapis.com/maps/api/geocode/json', [
                'address' => $query,
                'key' => (string) config('eventpulse.geocoding.google_key'),
            ]);

        $data = $response->json();

        if (! is_array($data) || ($data['status'] ?? null) !== 'OK' || ! isset($data['results'][0]['geometry']['location'])) {
            return [];
        }

        $location = $data['results'][0]['geometry']['location'];

        /** @var array<int, array<string, mixed>> $components */
        $components = is_array($data['results'][0]['address_components'] ?? null)
            ? $data['results'][0]['address_components']
            : [];

        return [
            'latitude' => (float) $location['lat'],
            'longitude' => (float) $location['lng'],
            'neighborhood' => $this->googleNeighborhood($components),
        ];
    }

    /**
     * Return the first non-empty string value among the given keys.
     *
     * @param  array<string, mixed>  $address
     * @param  list<string>  $keys
     */
    private function firstString(array $address, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! empty($address[$key]) && is_string($address[$key])) {
                return $address[$key];
            }
        }

        return null;
    }

    /**
     * Extract a neighborhood label from Google address components.
     *
     * @param  array<int, array<string, mixed>>  $components
     */
    private function googleNeighborhood(array $components): ?string
    {
        foreach (['neighborhood', 'sublocality', 'sublocality_level_1'] as $type) {
            foreach ($components as $component) {
                $types = $component['types'] ?? [];

                if (is_array($types) && in_array($type, $types, true) && is_string($component['long_name'] ?? null)) {
                    return $component['long_name'];
                }
            }
        }

        return null;
    }

    /**
     * Enrich an event with additional metadata fetched from its source URL.
     *
     * Re-fetches the event's source page to extract any additional data not
     * captured during the initial scrape (e.g., organizer info, full
     * description, ticket links, accessibility info).
     */
    public function enrichMetadata(Event $event): Event
    {
        if ($event->is_enriched) {
            return $event;
        }

        if (! $event->source_url) {
            $event->update(['is_enriched' => true]);

            return $event;
        }

        try {
            $timeout = (int) config('eventpulse.enrichment.timeout_seconds', 10);
            $response = $this->http->timeout($timeout)->get($event->source_url);

            if ($response->failed()) {
                $event->update(['is_enriched' => true]);

                return $event;
            }

            $extracted = $this->extractFromHtml($response->body());

            $attributes = ['is_enriched' => true];

            // Only backfill fields the scrape didn't already capture.
            if (($event->description === null || $event->description === '') && $extracted['description'] !== null) {
                $attributes['description'] = $extracted['description'];
            }

            if (($event->image_url === null || $event->image_url === '') && $extracted['image'] !== null) {
                $attributes['image_url'] = $extracted['image'];
            }

            if ($extracted['metadata'] !== []) {
                $attributes['metadata'] = array_merge($event->metadata ?? [], $extracted['metadata']);
            }

            $event->update($attributes);
        } catch (\Throwable $e) {
            Log::warning('EventEnricher: metadata enrichment failed', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);

            // Mark enriched so the pipeline does not retry endlessly.
            $event->update(['is_enriched' => true]);
        }

        return $event;
    }

    /**
     * Extract Open Graph tags and JSON-LD Event data from a source page.
     *
     * @return array{description: ?string, image: ?string, metadata: array<string, mixed>}
     */
    private function extractFromHtml(string $html): array
    {
        $result = ['description' => null, 'image' => null, 'metadata' => []];

        if (trim($html) === '') {
            return $result;
        }

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $encoded = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
        $dom->loadHTML($encoded, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        // Open Graph
        $ogImage = $this->metaContent($xpath, 'og:image');
        if ($ogImage !== null) {
            $result['image'] = $ogImage;
            $result['metadata']['og_image'] = $ogImage;
        }

        $ogDescription = $this->metaContent($xpath, 'og:description');
        if ($ogDescription !== null) {
            $result['description'] = $ogDescription;
        }

        // JSON-LD structured Event data (richer, preferred for missing fields)
        $jsonLd = $this->extractJsonLdEvent($xpath);

        if ($jsonLd !== []) {
            if ($result['description'] === null && isset($jsonLd['description']) && is_string($jsonLd['description'])) {
                $result['description'] = trim($jsonLd['description']);
            }

            if ($result['image'] === null) {
                $image = $this->jsonLdImage($jsonLd);
                if ($image !== null) {
                    $result['image'] = $image;
                }
            }

            foreach (['organizer', 'offers', 'performer', 'eventStatus', 'eventAttendanceMode'] as $key) {
                if (isset($jsonLd[$key])) {
                    $result['metadata']['jsonld_'.$key] = $jsonLd[$key];
                }
            }
        }

        return $result;
    }

    /**
     * Return the `content` of an OG/meta tag by property or name.
     */
    private function metaContent(\DOMXPath $xpath, string $property): ?string
    {
        foreach (["//meta[@property='{$property}']", "//meta[@name='{$property}']"] as $expression) {
            $nodes = $xpath->query($expression);

            if ($nodes !== false && $nodes->length > 0) {
                $node = $nodes->item(0);

                if ($node instanceof \DOMElement) {
                    $content = trim($node->getAttribute('content'));

                    if ($content !== '') {
                        return $content;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Find the first JSON-LD node whose @type is an Event (handles @graph and lists).
     *
     * @return array<string, mixed>
     */
    private function extractJsonLdEvent(\DOMXPath $xpath): array
    {
        $scripts = $xpath->query("//script[@type='application/ld+json']");

        if ($scripts === false) {
            return [];
        }

        foreach ($scripts as $script) {
            $json = trim($script->textContent);

            if ($json === '') {
                continue;
            }

            try {
                $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            $event = $this->findEventNode($data);

            if ($event !== null) {
                return $event;
            }
        }

        return [];
    }

    /**
     * Recursively locate an Event node in decoded JSON-LD.
     *
     * @return array<string, mixed>|null
     */
    private function findEventNode(mixed $data): ?array
    {
        if (! is_array($data)) {
            return null;
        }

        if (isset($data['@graph']) && is_array($data['@graph'])) {
            $found = $this->findEventNode($data['@graph']);
            if ($found !== null) {
                return $found;
            }
        }

        if (isset($data['@type']) && $this->isEventType($data['@type'])) {
            return $data;
        }

        if (array_is_list($data)) {
            foreach ($data as $node) {
                $found = $this->findEventNode($node);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function isEventType(mixed $type): bool
    {
        if (is_string($type)) {
            return str_contains($type, 'Event');
        }

        if (is_array($type)) {
            foreach ($type as $candidate) {
                if (is_string($candidate) && str_contains($candidate, 'Event')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Extract an image URL from a JSON-LD `image` field (string, list, or object).
     *
     * @param  array<string, mixed>  $jsonLd
     */
    private function jsonLdImage(array $jsonLd): ?string
    {
        $image = $jsonLd['image'] ?? null;

        if (is_string($image) && trim($image) !== '') {
            return trim($image);
        }

        if (is_array($image)) {
            if (isset($image['url']) && is_string($image['url']) && trim($image['url']) !== '') {
                return trim($image['url']);
            }

            $first = $image[0] ?? null;

            if (is_string($first) && trim($first) !== '') {
                return trim($first);
            }

            if (is_array($first) && isset($first['url']) && is_string($first['url'])) {
                return trim($first['url']);
            }
        }

        return null;
    }
}
