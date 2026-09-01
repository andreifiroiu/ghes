import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { Calendar, Clock, Hash, MapPin, Search, Tag, X } from 'lucide-react';
import { Input } from '@/Components/ui/Input';
import { cn } from '@/lib/utils';
import { CATEGORIES } from '@/lib/categories';
import { formatDayMonth } from '@/lib/dates';
import {
    clearRecentSearches,
    pushRecentSearch,
    readRecentSearches,
} from '@/lib/recentSearches';

const MIN_QUERY_LENGTH = 2;
const SUGGEST_DEBOUNCE_MS = 200;

const GROUP_ICONS = {
    recent: Clock,
    event: Calendar,
    category: Hash,
    tag: Tag,
    venue: MapPin,
};

/**
 * Categories whose Romanian label matches the term.
 *
 * Matched here rather than server-side because the labels users actually type
 * ("Muzică", "Gastronomie") live only in lib/categories.js — EventCategory is a
 * bare backed enum. Keeping the match next to the labels avoids a second source
 * of truth, and costs no round-trip.
 *
 * @param {string} term
 * @returns {Array<{ value: string, label: string }>}
 */
function matchCategories(term) {
    const needle = term.trim().toLowerCase();

    if (needle.length < MIN_QUERY_LENGTH) {
        return [];
    }

    return CATEGORIES.filter(({ label }) => label.toLowerCase().includes(needle)).slice(0, 5);
}

/**
 * Search box with an autocomplete dropdown over events, categories, tags,
 * venues and the visitor's own recent searches.
 *
 * Hand-rolled rather than pulled from cmdk/radix: the repo carries neither, and
 * a listbox of this shape is a smaller surface than a new dependency.
 *
 * @param {Object} props
 * @param {string} props.value - the current term (controlled by the page)
 * @param {(term: string) => void} props.onChange - fired on every keystroke, drives the live results
 * @param {(term: string) => void} props.onCommit - fired on Enter, or on picking a venue/recent entry
 * @param {(category: string) => void} props.onSelectCategory
 * @param {(tag: string) => void} props.onSelectTag
 * @param {(venue: string) => void} props.onSelectVenue
 * @param {boolean} [props.busy] - whether results are currently loading
 */
export default function SearchAutocomplete({
    value,
    onChange,
    onCommit,
    onSelectCategory,
    onSelectTag,
    onSelectVenue,
    busy = false,
}) {
    const listboxId = useId();
    const containerRef = useRef(null);
    const inputRef = useRef(null);
    const optionRefs = useRef([]);

    const [open, setOpen] = useState(false);
    const [activeIndex, setActiveIndex] = useState(-1);
    const [remote, setRemote] = useState({ events: [], venues: [], tags: [] });
    const [rateLimited, setRateLimited] = useState(false);
    const [recent, setRecent] = useState([]);

    useEffect(() => {
        setRecent(readRecentSearches());
    }, []);

    const term = value || '';
    const trimmed = term.trim();

    // Fetch suggestions for the current term. Debounced separately from the
    // results reload so the dropdown can feel quicker than the list behind it,
    // and aborted on every change so a slow response for "ja" can never land
    // after the one for "jazz".
    useEffect(() => {
        if (trimmed.length < MIN_QUERY_LENGTH) {
            setRemote({ events: [], venues: [], tags: [] });
            setRateLimited(false);

            return undefined;
        }

        const controller = new AbortController();
        const timer = setTimeout(() => {
            fetch(`/events/suggestions?q=${encodeURIComponent(trimmed)}`, {
                signal: controller.signal,
                headers: { Accept: 'application/json' },
            })
                .then((response) => {
                    // 429 is the one failure the reader can act on: the endpoint
                    // is throttled, and without a word the dropdown just appears
                    // to stop working.
                    setRateLimited(response.status === 429);

                    return response.ok ? response.json() : null;
                })
                .then((data) => {
                    if (data) {
                        setRemote({
                            events: data.events || [],
                            venues: data.venues || [],
                            tags: data.tags || [],
                        });
                    }
                })
                .catch((error) => {
                    // Aborting is how this effect cancels a stale lookup, so an
                    // AbortError is expected on nearly every keystroke and is
                    // not worth a word. Anything else — a network failure, or a
                    // genuine bug in the handler above — is reported rather than
                    // swallowed, so it cannot hide behind the abort.
                    if (error.name !== 'AbortError') {
                        console.warn('Suggestion lookup failed', error);
                    }
                });
        }, SUGGEST_DEBOUNCE_MS);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [trimmed]);

    const commit = useCallback(
        (nextTerm) => {
            const settled = (nextTerm ?? '').trim();

            setOpen(false);
            setActiveIndex(-1);
            setRecent(pushRecentSearch(settled));
            onChange(settled);
            onCommit(settled);
            inputRef.current?.blur();
        },
        [onChange, onCommit]
    );

    // One flat list backs the keyboard navigation; the render groups it again.
    // Keeping a single ordered array is what makes ArrowDown behave predictably
    // across group boundaries.
    const options = useMemo(() => {
        if (trimmed.length < MIN_QUERY_LENGTH) {
            return recent.map((entry) => ({
                kind: 'recent',
                key: `recent:${entry}`,
                label: entry,
                onPick: () => commit(entry),
            }));
        }

        return [
            ...remote.events.map((event) => ({
                kind: 'event',
                key: `event:${event.id}`,
                label: event.title,
                // formatDayMonth renders an em dash for a missing date, which
                // would read as a real value here — so only ask when there is one.
                hint: [event.venue, event.starts_at ? formatDayMonth(event.starts_at) : null]
                    .filter(Boolean)
                    .join(' · '),
                onPick: () => {
                    setOpen(false);
                    // Deliberately not stored as a recent search: the user
                    // picked an event, not a query, and half-typed prefixes
                    // are not searches anyone would want offered back.
                    router.visit(`/events/${event.id}`);
                },
            })),
            ...matchCategories(trimmed).map((category) => ({
                kind: 'category',
                key: `category:${category.value}`,
                label: category.label,
                hint: 'Categorie',
                onPick: () => {
                    setOpen(false);
                    onSelectCategory(category.value);
                },
            })),
            ...remote.tags.map((tag) => ({
                kind: 'tag',
                key: `tag:${tag}`,
                label: tag,
                hint: 'Etichetă',
                onPick: () => {
                    setOpen(false);
                    onSelectTag(tag);
                },
            })),
            ...remote.venues.map((venue) => ({
                kind: 'venue',
                key: `venue:${venue}`,
                label: venue,
                hint: 'Locație',
                onPick: () => {
                    setOpen(false);
                    onSelectVenue(venue);
                },
            })),
        ];
    }, [trimmed, remote, recent, commit, onSelectCategory, onSelectTag, onSelectVenue]);

    // Close on any pointer press outside the widget. `pointerdown` rather than
    // `click` so the dropdown is gone before a click elsewhere lands.
    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const handler = (event) => {
            if (!containerRef.current?.contains(event.target)) {
                setOpen(false);
                setActiveIndex(-1);
            }
        };

        document.addEventListener('pointerdown', handler);

        return () => document.removeEventListener('pointerdown', handler);
    }, [open]);

    // A shrinking list must never leave the highlight past its end.
    useEffect(() => {
        setActiveIndex((current) => (current >= options.length ? options.length - 1 : current));
    }, [options.length]);

    // The list scrolls at eight-ish items, so arrowing past the fold has to
    // bring the highlighted option back into view — the caret never moves off
    // the input, so the browser will not do it for us.
    useEffect(() => {
        if (activeIndex >= 0) {
            optionRefs.current[activeIndex]?.scrollIntoView({ block: 'nearest' });
        }
    }, [activeIndex]);

    const handleKeyDown = (event) => {
        if (event.key === 'Escape') {
            // Two-stage, per the ARIA combobox pattern: the first Escape
            // dismisses the suggestions, a second clears what was typed.
            if (open) {
                setOpen(false);
                setActiveIndex(-1);
            } else if (term) {
                onChange('');
                onCommit('');
            }

            return;
        }

        if (event.key === 'Tab') {
            setOpen(false);

            return;
        }

        if (event.key === 'Enter') {
            event.preventDefault();

            if (open && activeIndex >= 0 && options[activeIndex]) {
                options[activeIndex].onPick();

                return;
            }

            commit(term);

            return;
        }

        if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') {
            return;
        }

        event.preventDefault();

        if (!open) {
            setOpen(true);

            return;
        }

        if (options.length === 0) {
            return;
        }

        // Wrap at both ends so the list is a loop rather than a dead stop.
        setActiveIndex((current) => {
            // From "nothing highlighted", Down opens at the first option and Up
            // at the last. Falling through to the modulo below would put Up on
            // the second-to-last instead, because -1 is one before the first
            // rather than one past the end.
            if (current < 0) {
                return event.key === 'ArrowDown' ? 0 : options.length - 1;
            }

            const delta = event.key === 'ArrowDown' ? 1 : -1;

            return (current + delta + options.length) % options.length;
        });
    };

    const showRecentHeader = trimmed.length < MIN_QUERY_LENGTH && recent.length > 0;
    const activeOption = activeIndex >= 0 ? options[activeIndex] : null;

    return (
        <div ref={containerRef} className="relative flex-1">
            <div className="relative">
                <Search
                    className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400"
                    aria-hidden="true"
                />
                <Input
                    ref={inputRef}
                    type="text"
                    value={term}
                    onChange={(event) => {
                        onChange(event.target.value);
                        setOpen(true);
                        setActiveIndex(-1);
                    }}
                    onFocus={() => setOpen(true)}
                    onKeyDown={handleKeyDown}
                    placeholder="Caută evenimente..."
                    className="pl-9 pr-9"
                    role="combobox"
                    aria-expanded={open && options.length > 0}
                    aria-controls={listboxId}
                    aria-autocomplete="list"
                    aria-activedescendant={activeOption ? `${listboxId}-${activeIndex}` : undefined}
                    aria-label="Caută evenimente"
                    autoComplete="off"
                    // The endpoint rejects anything longer, and a pasted essay
                    // would otherwise kill the dropdown with a silent 422.
                    maxLength={100}
                    // Kept as type="text": a type="search" field draws the UA's
                    // own clear button, which would sit beside the custom one.
                    enterKeyHint="search"
                    autoCorrect="off"
                    autoCapitalize="off"
                    spellCheck={false}
                />
                {/* A spinner while results load, a clear button otherwise —
                    they occupy the same slot because they are never both useful. */}
                {busy ? (
                    <span
                        className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 animate-spin rounded-full border-2 border-gray-300 border-t-[#FF5733]"
                        role="status"
                        aria-label="Se caută"
                    />
                ) : (
                    term && (
                        <button
                            type="button"
                            onClick={() => {
                                onChange('');
                                onCommit('');
                                inputRef.current?.focus();
                            }}
                            className="absolute right-2 top-1/2 -translate-y-1/2 rounded-full p-1 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600"
                            aria-label="Șterge căutarea"
                        >
                            <X className="h-4 w-4" aria-hidden="true" />
                        </button>
                    )
                )}
            </div>

            {/* Announced to screen readers, which otherwise get no signal that
                suggestions appeared — the focus never leaves the input. */}
            <div className="sr-only" role="status" aria-live="polite">
                {open && options.length > 0 ? `${options.length} sugestii disponibile` : ''}
            </div>

            {open && rateLimited && options.length === 0 && (
                <div className="absolute z-50 mt-1 w-full rounded-md border border-gray-200 bg-white px-3 py-2 text-sm text-gray-500 shadow-lg">
                    Prea multe căutări. Încearcă din nou într-un minut.
                </div>
            )}

            {open && options.length > 0 && (
                <div className="absolute z-50 mt-1 w-full overflow-hidden rounded-md border border-gray-200 bg-white shadow-lg">
                    {/* Outside the listbox on purpose: a non-option child, and a
                        focusable one at that, is invalid inside role="listbox". */}
                    {showRecentHeader && (
                        <div className="flex items-center justify-between border-b border-gray-100 px-3 py-1.5">
                            <span className="text-xs font-medium uppercase tracking-wide text-gray-400">
                                Căutări recente
                            </span>
                            <button
                                type="button"
                                onClick={() => setRecent(clearRecentSearches())}
                                className="text-xs text-gray-400 underline hover:text-gray-600"
                            >
                                Șterge
                            </button>
                        </div>
                    )}

                    <ul
                        id={listboxId}
                        role="listbox"
                        aria-label="Sugestii"
                        className="max-h-80 overflow-y-auto overscroll-contain py-1"
                    >
                        {options.map((option, index) => {
                        const Icon = GROUP_ICONS[option.kind] || Search;

                            return (
                                <li
                                    key={option.key}
                                    id={`${listboxId}-${index}`}
                                    ref={(node) => {
                                        optionRefs.current[index] = node;
                                    }}
                                    role="option"
                                    aria-selected={index === activeIndex}
                                    // pointerdown, not click: the input's blur
                                    // would otherwise close the list before the
                                    // click landed.
                                    onPointerDown={(event) => {
                                        event.preventDefault();
                                        option.onPick();
                                    }}
                                    onMouseEnter={() => setActiveIndex(index)}
                                    className={cn(
                                        'flex min-h-11 cursor-pointer items-center gap-3 px-3 py-2 text-sm sm:min-h-0',
                                        index === activeIndex ? 'bg-gray-100' : 'bg-white'
                                    )}
                                >
                                    <Icon
                                        className="h-4 w-4 shrink-0 text-gray-400"
                                        aria-hidden="true"
                                    />
                                    <span className="min-w-0 flex-1 truncate text-gray-900">
                                        {option.label}
                                    </span>
                                    {option.hint && (
                                        <span className="shrink-0 truncate text-xs text-gray-400">
                                            {option.hint}
                                        </span>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                </div>
            )}
        </div>
    );
}
