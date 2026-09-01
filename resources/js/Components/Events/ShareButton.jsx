import { useEffect, useRef, useState } from 'react';
import { Button } from '@/Components/ui/Button';

/**
 * Query parameters that describe how *this* reader arrived, rather than which
 * event the page is about. They must never travel with a shared link: `reacted`
 * would tell the recipient we noted a reaction they never made, and `from`/`n`
 * would bill their visit to somebody else's digest.
 */
const ARRIVAL_PARAMS = ['reacted', 'from', 'n'];

/**
 * Share the current event.
 *
 * Uses the native share sheet where the browser offers one (mobile, and Safari
 * on desktop), and falls back to copying the link with a transient confirmation.
 * `navigator.share` is only present on secure origins, so plain-HTTP local
 * development always takes the clipboard path.
 *
 * @param {Object} props
 * @param {string} props.title - Event title, used as the share sheet's subject
 * @param {string} [props.url] - Defaults to the current page, minus arrival params
 */
export default function ShareButton({ title, url }) {
    // null = idle, 'copied' = link is on the clipboard, 'failed' = we could not
    // put it there. A failure has to say so: the most likely one is that
    // `navigator.clipboard` does not exist at all (it is secure-context gated,
    // so every plain-HTTP origin lacks it), and leaving the button untouched
    // there means the button does nothing, forever, with no explanation.
    const [status, setStatus] = useState(null);
    const timeoutRef = useRef(null);

    // The message is on a timer, so it has to be cleared if the user navigates
    // to another event before it elapses.
    useEffect(() => () => clearTimeout(timeoutRef.current), []);

    /**
     * The address bar, minus whatever brought this particular reader here.
     */
    const currentUrlWithoutArrivalParams = () => {
        const current = new URL(window.location.href);

        ARRIVAL_PARAMS.forEach((param) => current.searchParams.delete(param));

        return current.toString();
    };

    const flash = (next) => {
        setStatus(next);
        clearTimeout(timeoutRef.current);
        timeoutRef.current = setTimeout(() => setStatus(null), 2500);
    };

    const handleShare = async () => {
        const shareUrl = url || currentUrlWithoutArrivalParams();

        if (navigator.share) {
            try {
                await navigator.share({ title, url: shareUrl });

                return;
            } catch (error) {
                // Dismissing the sheet rejects with AbortError — a deliberate
                // "no", so don't fall back to copying behind their back.
                if (error?.name === 'AbortError') {
                    return;
                }

                // Anything else (no share target, invalid payload, missing user
                // activation) is a real failure. Record it and fall through.
                console.error('Share failed, falling back to clipboard:', error);
            }
        }

        try {
            await navigator.clipboard.writeText(shareUrl);
            flash('copied');
        } catch (error) {
            console.error('Could not copy the event link:', error);
            flash('failed');
        }
    };

    const label = { copied: 'Link copiat', failed: 'Nu am putut copia linkul' }[status];

    return (
        <Button
            type="button"
            variant="outline"
            className="w-full"
            onClick={handleShare}
            aria-live="polite"
        >
            {label ? (
                label
            ) : (
                <>
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
                            d="M8.684 13.342a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"
                        />
                    </svg>
                    Distribuie
                </>
            )}
        </Button>
    );
}
