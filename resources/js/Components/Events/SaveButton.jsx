import { useState, useCallback } from 'react';
import { Button } from '@/Components/ui/Button';
import { cn } from '@/lib/utils';
import { sendFeedback } from '@/lib/feedback';

/**
 * Bookmark toggle, independent of the taste reaction on the same event.
 *
 * Kept as its own component (and its own state and loading flag) so saving can
 * never overwrite a reaction, and a save in flight doesn't disable the reaction
 * buttons. Amber active styling distinguishes "saved" from the indigo used for
 * a selected reaction, so the two read as separate states.
 *
 * @param {Object} props
 * @param {string} props.eventId
 * @param {boolean} [props.isSaved]
 */
export default function SaveButton({ eventId, isSaved = false }) {
    const [saved, setSaved] = useState(isSaved);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

    const handleToggle = useCallback(async () => {
        const next = !saved;
        const previous = saved;
        setSaved(next);
        setLoading(true);
        setError(null);

        const result = await sendFeedback('/bookmarks', next ? 'POST' : 'DELETE', {
            event_id: eventId,
        });

        if (!result.ok) {
            setSaved(previous);
            setError(result.message);
        }

        setLoading(false);
    }, [eventId, saved]);

    return (
        <div className="flex flex-col gap-1">
            <Button
                variant={saved ? 'default' : 'ghost'}
                size="sm"
                disabled={loading}
                aria-pressed={saved}
                onClick={(e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    handleToggle();
                }}
                className={cn('text-xs', saved && 'bg-amber-500 text-white')}
            >
                <span>🔖</span>
                <span className="hidden sm:inline">
                    {saved ? 'Salvat' : 'Salvează'}
                </span>
            </Button>
            {error && (
                <p role="alert" className="text-xs text-red-600">
                    {error}
                </p>
            )}
        </div>
    );
}
