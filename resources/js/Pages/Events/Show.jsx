import { Head, Link, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/Components/ui/Card';
import { Button } from '@/Components/ui/Button';
import CategoryBadge from '@/Components/Events/CategoryBadge';
import ReactionButtons from '@/Components/Events/ReactionButtons';
import SaveButton from '@/Components/Events/SaveButton';
import ShareButton from '@/Components/Events/ShareButton';
import EventList from '@/Components/Events/EventList';
import EventSources from '@/Components/Events/EventSources';
import { formatPrice } from '@/lib/price';
import { sourceLabel } from '@/lib/sources';

/**
 * Event detail page.
 *
 * @param {Object} props
 * @param {Object} props.event - Serialized by App\Http\Resources\EventResource
 * @param {string} props.event.id
 * @param {string} props.event.title
 * @param {string} [props.event.description]
 * @param {string} [props.event.image_url]
 * @param {string} [props.event.starts_at]
 * @param {string} [props.event.ends_at]
 * @param {string} [props.event.venue]
 * @param {string} [props.event.address]
 * @param {string} [props.event.city]
 * @param {number|null} [props.event.latitude]
 * @param {number|null} [props.event.longitude]
 * @param {string} [props.event.category]
 * @param {Array<string>} [props.event.tags]
 * @param {number|null} [props.event.price_min]
 * @param {number|null} [props.event.price_max]
 * @param {boolean} [props.event.is_free]
 * @param {string} [props.event.source]
 * @param {string} [props.event.source_url]
 * @param {Array<{source: string, source_url: string}>} [props.event.sources]
 * @param {string} [props.event.click_url] Tracked redirect; carries the click through to the ticket site.
 * @param {string|null} [props.event.current_reaction]
 * @param {boolean} [props.event.is_saved]
 * @param {Array<Object>} [props.relatedEvents]
 */
export default function Show({ event, relatedEvents = [] }) {
    const { auth } = usePage().props;
    const isGuest = !auth?.user;

    const formatDateTime = (dateStr) => {
        if (!dateStr) return null;
        return new Date(dateStr).toLocaleDateString('ro-RO', {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    };

    const priceLabel = formatPrice(event);
    const isGeocoded = event.latitude != null && event.longitude != null;

    // Every provider that listed this event — both who to credit and where to
    // buy. Merged duplicates contribute one entry each, so a popular event
    // offers a choice of ticket vendors. Falls back to the single scraped URL
    // for events that predate the provenance table.
    const sourceLinks =
        event.sources?.length > 0
            ? event.sources
            : event.source_url
              ? [{ source: event.source, source_url: event.source_url }]
              : [];

    /**
     * Route a ticket link through the tracked redirect so the click is
     * recorded. `s` names which provider was chosen — the server resolves it
     * against the event's own sources, so it selects a destination rather than
     * supplying one. Falls back to the raw URL if the prop predates tracking.
     *
     * @param {{source: string, source_url: string}} link
     */
    const trackedTicketUrl = (link) =>
        event.click_url
            ? `${event.click_url}?from=event_detail&s=${encodeURIComponent(link.source)}`
            : link.source_url;

    return (
        <AppLayout>
            <Head title={event.title}>
                {event.description && (
                    <meta
                        head-key="description"
                        name="description"
                        content={event.description.slice(0, 160)}
                    />
                )}
            </Head>

            <div className="mb-6">
                <Link
                    href="/events"
                    className="inline-flex items-center text-sm text-gray-500 hover:text-gray-700"
                >
                    <svg
                        className="w-4 h-4 mr-1"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            strokeWidth={2}
                            d="M15 19l-7-7 7-7"
                        />
                    </svg>
                    Înapoi la evenimente
                </Link>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-8 lg:items-start">
                {/* Main content */}
                <div className="lg:col-span-2 space-y-6">
                    {/* Hero image */}
                    <div className="aspect-video bg-gray-100 rounded-lg overflow-hidden">
                        {event.image_url ? (
                            <img
                                src={event.image_url}
                                alt={event.title}
                                className="w-full h-full object-cover"
                            />
                        ) : (
                            <div className="w-full h-full flex items-center justify-center text-gray-300">
                                <svg
                                    className="w-16 h-16"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                    aria-hidden="true"
                                >
                                    <path
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        strokeWidth={1.5}
                                        d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
                                    />
                                </svg>
                            </div>
                        )}
                    </div>

                    {/* Title and meta */}
                    <div>
                        <div className="flex items-center gap-2 mb-2">
                            {event.category && (
                                <CategoryBadge category={event.category} />
                            )}
                        </div>
                        <h1 className="text-2xl sm:text-3xl font-bold text-gray-900 mb-4 break-words">
                            {event.title}
                        </h1>
                    </div>

                    {/* Description */}
                    {event.description && (
                        <Card>
                            <CardContent className="p-6">
                                <h2 className="text-lg font-semibold text-gray-900 mb-3">
                                    Despre acest eveniment
                                </h2>
                                <div className="prose prose-sm max-w-none text-gray-700 whitespace-pre-wrap break-words">
                                    {event.description}
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {/* Tags */}
                    {event.tags && event.tags.length > 0 && (
                        <div className="flex flex-wrap gap-2">
                            {event.tags.map((tag) => (
                                <span
                                    key={tag}
                                    className="inline-flex items-center rounded-full bg-gray-100 px-3 py-1 text-sm text-gray-600"
                                >
                                    {tag}
                                </span>
                            ))}
                        </div>
                    )}

                    {/* Credits — who reported this event to us */}
                    <EventSources
                        sources={sourceLinks}
                        hrefFor={trackedTicketUrl}
                    />
                </div>

                {/* Sidebar — hoisted above the description on mobile */}
                <div className="space-y-6 order-first lg:order-none">
                    {/* Details card */}
                    <Card>
                        <CardContent className="p-6 space-y-4">
                            {/* Date and time */}
                            {event.starts_at && (
                                <div className="flex items-start gap-3">
                                    <svg
                                        className="w-5 h-5 text-gray-400 mt-0.5 flex-shrink-0"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                        aria-hidden="true"
                                    >
                                        <path
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            strokeWidth={2}
                                            d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
                                        />
                                    </svg>
                                    <div>
                                        <p className="text-sm font-medium text-gray-900">
                                            {formatDateTime(event.starts_at)}
                                        </p>
                                        {event.ends_at && (
                                            <p className="text-sm text-gray-500">
                                                Până la{' '}
                                                {formatDateTime(event.ends_at)}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            )}

                            {/* Venue */}
                            {(event.venue || event.address || event.city) && (
                                <div className="flex items-start gap-3">
                                    <svg
                                        className="w-5 h-5 text-gray-400 mt-0.5 flex-shrink-0"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                        aria-hidden="true"
                                    >
                                        <path
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            strokeWidth={2}
                                            d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"
                                        />
                                        <path
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            strokeWidth={2}
                                            d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"
                                        />
                                    </svg>
                                    <div>
                                        {event.venue && (
                                            <p className="text-sm font-medium text-gray-900">
                                                {event.venue}
                                            </p>
                                        )}
                                        {event.address && (
                                            <p className="text-sm text-gray-500">
                                                {event.address}
                                            </p>
                                        )}
                                        {event.city && (
                                            <p className="text-sm text-gray-500">
                                                {event.city}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            )}

                            {/* Price */}
                            {priceLabel && (
                                <div className="flex items-start gap-3">
                                    <svg
                                        className="w-5 h-5 text-gray-400 mt-0.5 flex-shrink-0"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                        aria-hidden="true"
                                    >
                                        <path
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            strokeWidth={2}
                                            d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                                        />
                                    </svg>
                                    <p className="text-sm font-medium text-indigo-600">
                                        {priceLabel}
                                    </p>
                                </div>
                            )}

                            {/* Ticket links — one per provider that listed this
                                event, each named so a single-source event
                                credits its provider instead of hiding it
                                behind a generic label. */}
                            {sourceLinks.length > 0 && (
                                <div className="pt-2 space-y-2">
                                    {sourceLinks.length > 1 && (
                                        <p className="text-xs font-medium uppercase tracking-wide text-gray-400">
                                            Disponibil pe
                                        </p>
                                    )}
                                    {sourceLinks.map((link) => (
                                        <a
                                            key={link.source_url}
                                            href={trackedTicketUrl(link)}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="block"
                                        >
                                            <Button
                                                variant="outline"
                                                className="w-full"
                                            >
                                                {sourceLabel(link.source)
                                                    ? `Vezi pe ${sourceLabel(link.source)}`
                                                    : 'Vezi sursa originală'}
                                            </Button>
                                        </a>
                                    ))}
                                </div>
                            )}

                            {/* Actions. The calendar download is offered only
                                for a dated event — the route 404s otherwise,
                                because a calendar entry invented from an
                                unparsed date reads as a real commitment. */}
                            <div className="space-y-2">
                                {event.starts_at ? (
                                    <a
                                        href={`/events/${event.id}/calendar.ics`}
                                        className="block"
                                    >
                                        <Button variant="outline" className="w-full">
                                            <svg
                                                className="w-4 h-4 mr-2"
                                                fill="none"
                                                stroke="currentColor"
                                                viewBox="0 0 24 24"
                                                aria-hidden="true"
                                            >
                                                <path
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                    strokeWidth={2}
                                                    d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
                                                />
                                            </svg>
                                            Adaugă în calendar
                                        </Button>
                                    </a>
                                ) : (
                                    <p className="text-sm text-gray-500">
                                        Data nu este confirmată.
                                    </p>
                                )}
                                <ShareButton title={event.title} />
                            </div>
                        </CardContent>
                    </Card>

                    {/* Map — desktop only; the sidebar is already long on mobile */}
                    <Card className="hidden lg:block">
                        <CardContent className="p-0">
                            {isGeocoded ? (
                                <div className="overflow-hidden rounded-lg">
                                    <iframe
                                        title={`Hartă: ${event.venue || event.title}`}
                                        className="w-full aspect-square border-0"
                                        loading="lazy"
                                        referrerPolicy="no-referrer"
                                        src={openStreetMapEmbed(
                                            event.latitude,
                                            event.longitude
                                        )}
                                    />
                                    <a
                                        href={`https://www.openstreetmap.org/?mlat=${event.latitude}&mlon=${event.longitude}#map=16/${event.latitude}/${event.longitude}`}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="block px-4 py-3 text-sm text-indigo-600 hover:text-indigo-700"
                                    >
                                        Vezi pe hartă
                                    </a>
                                </div>
                            ) : (
                                <div className="aspect-square bg-gray-100 rounded-lg flex items-center justify-center text-gray-400">
                                    <div className="text-center">
                                        <svg
                                            className="w-10 h-10 mx-auto mb-2"
                                            fill="none"
                                            stroke="currentColor"
                                            viewBox="0 0 24 24"
                                            aria-hidden="true"
                                        >
                                            <path
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                                strokeWidth={1.5}
                                                d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"
                                            />
                                        </svg>
                                        <p className="text-sm">
                                            Locație neconfirmată
                                        </p>
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Reactions — guests get the pitch instead */}
                    <Card>
                        <CardContent className="p-4">
                            {isGuest ? (
                                <>
                                    <p className="text-sm font-medium text-gray-700 mb-1">
                                        Îți place genul ăsta?
                                    </p>
                                    <p className="text-sm text-gray-500 mb-3">
                                        Fă-ți cont și primești evenimente pe gustul tău,
                                        fără să mai cauți.
                                    </p>
                                    <Link href="/register">
                                        <Button className="w-full">
                                            Înregistrează-te gratuit
                                        </Button>
                                    </Link>
                                </>
                            ) : (
                                <>
                                    <p className="text-sm font-medium text-gray-700 mb-3">
                                        Ce părere ai?
                                    </p>
                                    <div className="flex items-center gap-2 flex-wrap">
                                        <ReactionButtons
                                            eventId={event.id}
                                            currentReaction={event.current_reaction}
                                        />
                                        <SaveButton
                                            eventId={event.id}
                                            isSaved={event.is_saved}
                                        />
                                    </div>
                                </>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>

            {/* Related events — omitted entirely when nothing scored */}
            {relatedEvents.length > 0 && (
                <section className="mt-12">
                    <h2 className="text-xl font-bold text-gray-900 mb-6">
                        Evenimente similare
                    </h2>
                    <EventList
                        events={relatedEvents}
                        showReactions={!isGuest}
                    />
                </section>
            )}
        </AppLayout>
    );
}

/**
 * OpenStreetMap's embeddable map, centred on a small bounding box around the
 * point with a marker on it. Used instead of a mapping library so the page
 * stays dependency-free — this is the app's only third-party embed.
 *
 * @param {number} latitude
 * @param {number} longitude
 * @returns {string}
 */
function openStreetMapEmbed(latitude, longitude) {
    // Roughly a 500m box, which frames a venue without losing the street names.
    const padding = 0.004;
    const bbox = [
        longitude - padding,
        latitude - padding,
        longitude + padding,
        latitude + padding,
    ].join(',');

    return `https://www.openstreetmap.org/export/embed.html?bbox=${bbox}&layer=mapnik&marker=${latitude},${longitude}`;
}
