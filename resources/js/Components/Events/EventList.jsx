import EventCard from '@/Components/Events/EventCard';

/**
 * @param {Object} props
 * @param {Array<Object>} props.events
 * @param {string} [props.emptyMessage]
 * @param {boolean} [props.showReactions] - Off for guests, who cannot react
 */
export default function EventList({
    events = [],
    emptyMessage = 'Niciun eveniment găsit.',
    showReactions = true,
}) {
    if (events.length === 0) {
        return (
            <div className="text-center py-12 text-gray-400">
                <svg
                    className="mx-auto w-12 h-12 mb-4"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        strokeWidth={1.5}
                        d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"
                    />
                </svg>
                <p className="text-sm">{emptyMessage}</p>
            </div>
        );
    }

    return (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
            {events.map((event) => (
                <EventCard
                    key={event.id}
                    event={event}
                    showReactions={showReactions}
                />
            ))}
        </div>
    );
}
