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
        // TODO: If $event->is_enriched is true, return $event unchanged
        // TODO: If $event->source_url is null, mark as enriched and return
        // TODO: Fetch the source URL using $this->http->get($event->source_url)
        // TODO: Wrap in try/catch with a timeout of config('eventpulse.enrichment.timeout_seconds', 10)
        // TODO: On success:
        //   TODO: Parse HTML with DOMDocument
        //   TODO: Extract Open Graph meta tags (og:image, og:description) if not already set
        //   TODO: Extract structured data (JSON-LD) if present for richer event details
        //   TODO: Update event metadata JSONB column with any new fields
        //   TODO: Update image_url if found and not already set
        // TODO: Set $event->is_enriched = true
        // TODO: Save the event
        // TODO: On failure: log warning, mark as enriched to prevent retry loops
        // TODO: Return the updated event
        return $event;
    }
}
