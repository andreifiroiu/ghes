import { Link } from '@inertiajs/react';
import { Card, CardContent } from '@/Components/ui/Card';
import CategoryBadge from '@/Components/Events/CategoryBadge';
import ReactionButtons from '@/Components/Events/ReactionButtons';
import SaveButton from '@/Components/Events/SaveButton';
import { formatPrice } from '@/lib/price';
import { sourceLabel as labelForSource } from '@/lib/sources';

/**
 * @param {Object} props
 * @param {Object} props.event
 * @param {string} props.event.id
 * @param {string} props.event.title
 * @param {string} [props.event.image_url]
 * @param {string} [props.event.starts_at]
 * @param {string} [props.event.venue]
 * @param {string} [props.event.category]
 * @param {number|null} [props.event.price_min]
 * @param {number|null} [props.event.price_max]
 * @param {string} [props.event.currency]
 * @param {boolean} [props.event.is_free]
 * @param {string} [props.event.source]
 * @param {string|null} [props.event.current_reaction]
 * @param {boolean} [props.event.is_saved]
 * @param {boolean} [props.showReactions] - Off for guests, who cannot react or save
 */
export default function EventCard({ event, showReactions = true }) {
    const formattedDate = event.starts_at
        ? new Date(event.starts_at).toLocaleDateString(undefined, {
              weekday: 'short',
              month: 'short',
              day: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          })
        : null;

    const sourceLabel = labelForSource(event.source);

    const priceLabel = formatPrice(event);

    return (
        <Card className="overflow-hidden hover:shadow-md transition-shadow flex flex-col">
            <Link
                href={`/events/${event.id}`}
                className="block"
                aria-label={event.title}
            >
                <div className="aspect-video bg-gray-100 relative overflow-hidden">
                    {event.image_url ? (
                        <img
                            src={event.image_url}
                            alt={event.title}
                            className="w-full h-full object-cover"
                        />
                    ) : (
                        <div className="w-full h-full flex items-center justify-center text-gray-300">
                            <svg
                                className="w-12 h-12"
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
                    {event.category && (
                        <div className="absolute top-2 left-2">
                            <CategoryBadge category={event.category} />
                        </div>
                    )}
                </div>
                <CardContent className="p-4 flex flex-col gap-1">
                    <h3 className="font-semibold text-gray-900 line-clamp-2">
                        {event.title}
                    </h3>
                    {formattedDate && (
                        <p className="text-sm text-gray-500">{formattedDate}</p>
                    )}
                    {event.venue && (
                        <p className="text-sm text-gray-500 truncate">{event.venue}</p>
                    )}
                    {priceLabel && (
                        <p className="text-sm font-medium" style={{ color: '#FF5733' }}>
                            {priceLabel}
                        </p>
                    )}
                    {sourceLabel && (
                        <p className="text-xs text-gray-400 flex items-center gap-1 mt-1">
                            <svg className="w-3 h-3 flex-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 12a9 9 0 11-18 0 9 9 0 0118 0zM3.6 9h16.8M3.6 15h16.8M12 3a15 15 0 010 18 15 15 0 010-18z" />
                            </svg>
                            {sourceLabel}
                        </p>
                    )}
                </CardContent>
            </Link>
            {showReactions && (
                <div className="px-4 pb-4 mt-auto flex items-center gap-2">
                    <ReactionButtons
                        eventId={event.id}
                        currentReaction={event.current_reaction}
                    />
                    <div className="ml-auto">
                        <SaveButton eventId={event.id} isSaved={event.is_saved} />
                    </div>
                </div>
            )}
        </Card>
    );
}
