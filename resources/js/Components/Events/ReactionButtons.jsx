import { useState, useCallback } from 'react';
import { Button } from '@/Components/ui/Button';
import { cn } from '@/lib/utils';

const reactions = [
    { key: 'interested', emoji: '\u2764\uFE0F', label: 'Interesant' },
    { key: 'not_interested', emoji: '\uD83D\uDC4E', label: 'Nu-i pentru mine' },
    { key: 'saved', emoji: '\uD83D\uDD16', label: 'Salveaza' },
    { key: 'hidden', emoji: '\uD83D\uDE48', label: 'Ascunde' },
];

/**
 * @param {Object} props
 * @param {string} props.eventId
 * @param {string|null} [props.currentReaction]
 */
export default function ReactionButtons({ eventId, currentReaction = null }) {
    const [active, setActive] = useState(currentReaction);
    const [loading, setLoading] = useState(false);

    const handleReaction = useCallback(
        async (reactionKey) => {
            const newReaction = active === reactionKey ? null : reactionKey;
            const previousReaction = active;
            setActive(newReaction);
            setLoading(true);

            try {
                const response = await fetch('/feedback', {
                    method: newReaction === null ? 'DELETE' : 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document
                            .querySelector('meta[name="csrf-token"]')
                            ?.getAttribute('content') || '',
                        Accept: 'application/json',
                    },
                    body: JSON.stringify(
                        newReaction === null
                            ? { event_id: eventId }
                            : { event_id: eventId, reaction: newReaction }
                    ),
                });

                if (!response.ok) {
                    setActive(previousReaction);
                }
            } catch {
                setActive(previousReaction);
            } finally {
                setLoading(false);
            }
        },
        [eventId, active]
    );

    return (
        <div className="flex items-center gap-1 flex-wrap">
            {reactions.map(({ key, emoji, label }) => (
                <Button
                    key={key}
                    variant={active === key ? 'default' : 'ghost'}
                    size="sm"
                    disabled={loading}
                    onClick={(e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        handleReaction(key);
                    }}
                    className={cn(
                        'text-xs',
                        active === key && 'bg-indigo-600 text-white'
                    )}
                >
                    <span>{emoji}</span>
                    <span className="hidden sm:inline">{label}</span>
                </Button>
            ))}
        </div>
    );
}
