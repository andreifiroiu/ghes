<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Models\Event;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The only place schema.org markup is built.
 *
 * Plain arrays rather than a builder package: Ghes needs five node types, and
 * PHPDoc-shaped arrays through `json_encode` are less code than the fluent API
 * would be for that surface — and no new dependency to justify.
 *
 * Nodes are handed to SeoManager under a name, so a second call for the same
 * node replaces it rather than emitting two page entities. Blade never writes
 * JSON-LD by hand: Blade compiles a literal `@context` as its context
 * directive, which is how pretulmeu shipped raw PHP as its FAQPage for weeks.
 *
 * Every node is self-contained rather than cross-referenced through a graph.
 * A graph would save a few hundred bytes per page and cost the ability to
 * reason about one node in isolation; at this size that is the wrong trade.
 */
class StructuredData
{
    /**
     * The site-wide publisher node.
     *
     * Ghes is a personal project, not a company, so `legalName` is emitted only
     * when someone has configured one — an invented legal name is worse than no
     * legal name, because it is the field that ties a domain to a registration.
     *
     * @return array<string, mixed>
     */
    public function organization(): array
    {
        /** @var array{legal_name: string|null, email: string, logo: string, city: string, country: string, same_as: list<string>} $config */
        $config = config('eventpulse.seo.organization');

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            '@id' => url('/#organization'),
            'name' => config('app.name'),
            'legalName' => $config['legal_name'],
            'url' => url('/'),
            'logo' => url($config['logo']),
            'email' => $config['email'],
            'areaServed' => [
                '@type' => 'City',
                'name' => $config['city'],
                'addressCountry' => $config['country'],
            ],
            'sameAs' => $config['same_as'] === [] ? null : $config['same_as'],
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * The home page's site node, carrying the search action so an engine can
     * offer the browse box as a sitelinks searchbox.
     *
     * @return array<string, mixed>
     */
    public function website(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            '@id' => url('/#website'),
            'name' => config('app.name'),
            'url' => url('/'),
            'inLanguage' => config('eventpulse.seo.defaults.language'),
            'publisher' => ['@id' => url('/#organization')],
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => route('events.index').'?search={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    /**
     * One event, as the richest node the stored columns can support.
     *
     * Deliberately conservative about what it claims. `eventStatus` is always
     * scheduled: Ghes scrapes listings and has no signal for a cancellation, and
     * announcing `EventScheduled` for something that was called off is the kind
     * of wrong answer a rich result gets punished for — but so is omitting the
     * field, and a listing that is still published is the best evidence
     * available. `offers` is emitted only when a price is actually known;
     * `is_free` alone is enough for a zero-price offer, since that is a positive
     * claim the classifier makes rather than an absence of data.
     *
     * @return array<string, mixed>
     */
    public function event(Event $event): array
    {
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            '@id' => route('events.show', $event).'#event',
            'name' => $event->title,
            'description' => $this->plainText($event->description),
            'url' => route('events.show', $event),
            'inLanguage' => config('eventpulse.seo.defaults.language'),
            'eventStatus' => 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            'startDate' => $event->starts_at?->toIso8601String(),
            'endDate' => $event->ends_at?->toIso8601String(),
            'image' => $event->image_url,
            'location' => $this->place($event),
            'offers' => $this->offer($event),
            'organizer' => ['@id' => url('/#organization')],
            'isAccessibleForFree' => $event->is_free ? true : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * A browse page's result list.
     *
     * `ItemList` with URLs rather than inlined `Event` nodes: the list is
     * paginated and filtered, so inlining would restate on every page the same
     * entities the detail pages already describe fully, and a crawler that
     * follows the URL gets the authoritative copy.
     *
     * @param  Collection<int, Event>|iterable<int, Event>  $events
     * @return array<string, mixed>
     */
    public function eventList(iterable $events, string $name): array
    {
        $position = 0;
        $items = [];

        foreach ($events as $event) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => ++$position,
                'url' => route('events.show', $event),
                'name' => $event->title,
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => $name,
            'numberOfItems' => count($items),
            'itemListElement' => $items,
        ];
    }

    /**
     * @param  list<array{name: string, url?: string}>  $items  the trail from the home page down; the last item may omit its URL
     * @return array<string, mixed>
     */
    public function breadcrumbs(array $items): array
    {
        $position = 0;

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(
                static function (array $item) use (&$position): array {
                    $listItem = [
                        '@type' => 'ListItem',
                        'position' => ++$position,
                        'name' => $item['name'],
                    ];

                    if (isset($item['url'])) {
                        $listItem['item'] = $item['url'];
                    }

                    return $listItem;
                },
                $items,
            ),
        ];
    }

    /**
     * Where the event happens.
     *
     * Needs a name at minimum — a Place with only coordinates is not a rich
     * result, it is a pin. `address` is emitted as a PostalAddress only when
     * there is a street to put in it; otherwise the city carries the location,
     * which is the common case for a scraped listing.
     *
     * @return array<string, mixed>|null
     */
    private function place(Event $event): ?array
    {
        $name = $event->venue ?? $event->city;

        if ($name === null || $name === '') {
            return null;
        }

        // Built before the '@type' is added, and the emptiness decided on the
        // real fields. Filtering an array that already carries its '@type'
        // never comes back empty, so an event with no street and no city would
        // still have emitted a bare {"@type":"PostalAddress"} — a node that
        // claims an address and states none.
        $address = array_filter([
            'streetAddress' => $event->address,
            'addressLocality' => $event->city,
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        $place = ['@type' => 'Place', 'name' => $name];

        if ($address !== []) {
            $place['address'] = [
                '@type' => 'PostalAddress',
                ...$address,
                'addressCountry' => config('eventpulse.seo.organization.country'),
            ];
        }

        if ($event->latitude !== null && $event->longitude !== null) {
            $place['geo'] = [
                '@type' => 'GeoCoordinates',
                'latitude' => (float) $event->latitude,
                'longitude' => (float) $event->longitude,
            ];
        }

        return $place;
    }

    /**
     * What it costs to get in.
     *
     * `url` points at the provider's own page rather than at our redirect: an
     * Offer's url is where a reader buys, and `go/{event}` is a tracking hop
     * that robots.txt disallows — advertising a disallowed URL as the purchase
     * link is a contradiction a validator will flag.
     *
     * `price_max` is not expressed. An AggregateOffer with a range would be
     * truer, but the range Ghes stores comes from parsing a listing page and is
     * frequently the difference between a ticket tier and a booking fee; the
     * lowest price is the one claim that is reliably right.
     *
     * @return array<string, mixed>|null
     */
    private function offer(Event $event): ?array
    {
        if (! $event->is_free && $event->price_min === null) {
            return null;
        }

        return array_filter([
            '@type' => 'Offer',
            // Formatted rather than cast: `price_min` is a float, so (string)
            // gives "50" for one row and "49.5" for the next, and on a decimal
            // column Postgres and sqlite disagree about the trailing zeros.
            // Two places is what a currency amount means.
            'price' => $event->is_free ? '0' : number_format((float) $event->price_min, 2, '.', ''),
            'priceCurrency' => $event->currency,
            'availability' => 'https://schema.org/InStock',
            'url' => $event->source_url,
            'validFrom' => $event->created_at?->toIso8601String(),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * Scraped descriptions carry provider markup. A rich result wants prose, and
     * a 5,000-character dump of a venue's boilerplate is not what an answer
     * engine should quote.
     */
    private function plainText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = Str::squish(strip_tags($value));

        return $value === '' ? null : Str::limit($value, 500);
    }
}
