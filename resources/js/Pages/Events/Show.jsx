import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/Components/ui/Card';
import { Button } from '@/Components/ui/Button';
import CategoryBadge from '@/Components/Events/CategoryBadge';
import ReactionButtons from '@/Components/Events/ReactionButtons';
import SaveButton from '@/Components/Events/SaveButton';

/**
 * @param {Object} props
 * @param {Object} props.event
 * @param {string} props.event.id
 * @param {string} props.event.title
 * @param {string} [props.event.description]
 * @param {string} [props.event.image_url]
 * @param {string} [props.event.starts_at]
 * @param {string} [props.event.ends_at]
 * @param {string} [props.event.venue_name]
 * @param {string} [props.event.venue_address]
 * @param {string} [props.event.category]
 * @param {Array<string>} [props.event.tags]
 * @param {string} [props.event.price]
 * @param {string} [props.event.source_url]
 * @param {string|null} [props.event.current_reaction]
 * @param {boolean} [props.event.is_saved]
 */
export default function Show({ event }) {
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

    return (
        <AppLayout>
            <Head title={event.title} />

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

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
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
                        <h1 className="text-3xl font-bold text-gray-900 mb-4">
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
                                <div className="prose prose-sm max-w-none text-gray-700 whitespace-pre-wrap">
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
                </div>

                {/* Sidebar */}
                <div className="space-y-6">
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
                            {(event.venue_name || event.venue_address) && (
                                <div className="flex items-start gap-3">
                                    <svg
                                        className="w-5 h-5 text-gray-400 mt-0.5 flex-shrink-0"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
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
                                        {event.venue_name && (
                                            <p className="text-sm font-medium text-gray-900">
                                                {event.venue_name}
                                            </p>
                                        )}
                                        {event.venue_address && (
                                            <p className="text-sm text-gray-500">
                                                {event.venue_address}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            )}

                            {/* Price */}
                            {event.price && (
                                <div className="flex items-start gap-3">
                                    <svg
                                        className="w-5 h-5 text-gray-400 mt-0.5 flex-shrink-0"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            strokeWidth={2}
                                            d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                                        />
                                    </svg>
                                    <p className="text-sm font-medium text-indigo-600">
                                        {event.price}
                                    </p>
                                </div>
                            )}

                            {/* Source link */}
                            {event.source_url && (
                                <a
                                    href={event.source_url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <Button variant="outline" className="w-full mt-2">
                                        Vezi sursa originală
                                    </Button>
                                </a>
                            )}
                        </CardContent>
                    </Card>

                    {/* Map placeholder */}
                    <Card>
                        <CardContent className="p-0">
                            <div className="aspect-square bg-gray-100 rounded-lg flex items-center justify-center text-gray-400">
                                <div className="text-center">
                                    <svg
                                        className="w-10 h-10 mx-auto mb-2"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            strokeWidth={1.5}
                                            d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"
                                        />
                                    </svg>
                                    <p className="text-sm">Hartă</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Reactions */}
                    <Card>
                        <CardContent className="p-4">
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
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
