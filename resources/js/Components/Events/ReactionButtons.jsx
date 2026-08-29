import { useState, useCallback } from 'react';
import { Button } from '@/Components/ui/Button';
import { cn } from '@/lib/utils';
import { sendFeedback } from '@/lib/feedback';

/**
 * Taste signals only. These two are mutually exclusive — bookmarking lives in
 * SaveButton and is independent, so an event can be both interesting and saved.
 */
const reactions = [
    { key: 'interested', emoji: '❤️', label: 'Mă interesează' },
    { key: 'not_interested', emoji: '👎', label: 'Nu-i pentru mine' },
];

/**
 * @param {Object} props
 * @param {string} props.eventId
 * @param {string|null} [props.currentReaction]
 */
export default function ReactionButtons({ eventId, currentReaction = null }) {
    const [active, setActive] = useState(currentReaction);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

    const handleReaction = useCallback(
        async (reactionKey) => {
            const newReaction = active === reactionKey ? null : reactionKey;
            const previousReaction = active;
            setActive(newReaction);
            setLoading(true);
            setError(null);

            const result = await sendFeedback(
                '/feedback',
                newReaction === null ? 'DELETE' : 'POST',
                newReaction === null
                    ? { event_id: eventId }
                    : { event_id: eventId, reaction: newReaction }
            );

            if (!result.ok) {
                setActive(previousReaction);
                setError(result.message);
            }

            setLoading(false);
        },
        [eventId, active]
    );

    return (
        <div className="flex flex-col gap-1">
            <div className="flex items-center gap-2 flex-wrap">
                {reactions.map(({ key, emoji, label }) => (
                    <Button
                        key={key}
                        variant={active === key ? 'default' : 'ghost'}
                        size="sm"
                        disabled={loading}
                        aria-pressed={active === key}
                        aria-label={label}
                        title={label}
                        onClick={(e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            handleReaction(key);
                        }}
                        className={cn(
                            'text-xs min-h-11 min-w-11 sm:min-h-0 sm:min-w-0',
                            active === key && 'bg-indigo-600 text-white'
                        )}
                    >
                        <span>{emoji}</span>
                        <span className="hidden sm:inline">{label}</span>
                    </Button>
                ))}
            </div>
            {error && (
                <p role="alert" className="text-xs text-red-600">
                    {error}
                </p>
            )}
        </div>
    );
}
