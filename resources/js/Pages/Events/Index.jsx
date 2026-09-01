import { useCallback, useEffect, useRef, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { X } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import EventList from '@/Components/Events/EventList';
import SearchAutocomplete from '@/Components/Events/SearchAutocomplete';
import { Input } from '@/Components/ui/Input';
import { Button } from '@/Components/ui/Button';
import { cn } from '@/lib/utils';
import { CATEGORIES } from '@/lib/categories';

/** How long typing must settle before the results reload. */
const SEARCH_DEBOUNCE_MS = 300;

/**
 * Shortest term that triggers a live reload. Mirrors
 * `eventpulse.search.min_suggestion_length` — the dropdown already refuses to
 * query below it, and the results list should not be more expensive than the
 * suggestions that accompany it.
 */
const MIN_LIVE_SEARCH_LENGTH = 2;

/**
 * @param {Object} props
 * @param {Object} props.events - Paginated events object
 * @param {Array<Object>} props.events.data
 * @param {Object} props.events.links - { first, last, prev, next }
 * @param {Object} props.events.meta - { current_page, last_page, total, ... }
 * @param {string} [props.filters.search]
 * @param {string} [props.filters.category]
 * @param {string} [props.filters.date]
 */
export default function Index({ events = {}, filters = {} }) {
    const { auth } = usePage().props;
    const isGuest = !auth?.user;
    const eventData = events.data || events;
    const [search, setSearch] = useState(filters.search || '');
    const [searching, setSearching] = useState(false);
    const activeCategory = filters.category || null;
    const activeDate = filters.date || '';
    const today = new Date().toISOString().slice(0, 10);

    // The term the server is currently showing results for. Compared against
    // local state to decide whether a debounced reload is still needed, so
    // arriving back on the page does not immediately re-request what it holds.
    const appliedSearch = filters.search || '';

    /**
     * Reload the list.
     *
     * `city` and `range` are carried through even though no control on this
     * page sets them: browseQuery() supports both and the landing page links
     * here with `?range=weekend`, so dropping them silently turned a weekend
     * view into an all-events one on the first click of any other filter.
     *
     * `live` marks a reload fired mid-typing. The server does not record those
     * as searches, though it still counts the impressions — see
     * EventController::recordBrowse().
     */
    const applyFilters = useCallback(
        (overrides, { live = false } = {}) => {
            const params = {
                // Mirrors EventController::FILTER_KEYS — every filter the
                // server understands has to be carried, or interacting with one
                // control silently discards the others.
                search,
                category: activeCategory,
                date: activeDate,
                city: filters.city || null,
                tag: filters.tag || null,
                venue: filters.venue || null,
                range: filters.range || null,
                ...overrides,
                // Any change of filter invalidates the current offset — without
                // this, narrowing while on page 3 lands on an empty one.
                page: null,
            };

            router.get(
                '/events',
                // Inertia serialises null as a bare `key=` rather than dropping
                // it, and this URL is the one people copy out of the address bar.
                Object.fromEntries(
                    Object.entries(params).filter(([, value]) => value !== null && value !== '')
                ),
                {
                    preserveState: true,
                    preserveScroll: true,
                    // Only the list and the echoed filters can change here.
                    only: ['events', 'filters'],
                    // Keystrokes replace the history entry so Back does not walk
                    // the alphabet; a committed search is a real navigation and
                    // keeps its entry.
                    replace: live,
                    // A header rather than a query param: `withQueryString()`
                    // would copy `live=1` onto the paginator links, silencing
                    // the logging for every pagination click that followed.
                    headers: live ? { 'X-Ghes-Live-Search': '1' } : {},
                    onStart: () => setSearching(true),
                    onFinish: () => setSearching(false),
                }
            );
        },
        [search, activeCategory, activeDate, filters.city, filters.tag, filters.venue, filters.range]
    );

    // Only typing arms the debounce. Without this flag, any handler that
    // clears the box — picking a tag, a category or a venue — would leave
    // `search` empty while `appliedSearch` still held the old term, and the
    // effect would fire 300ms later with stale props, navigating away from the
    // filter that was just applied.
    const isTyping = useRef(false);

    const handleSearchChange = useCallback((term) => {
        isTyping.current = true;
        setSearch(term);
    }, []);

    // Live search: reload once typing settles. Skipped when local state already
    // matches what the server rendered, which is the case on first mount and
    // once a search has come back.
    useEffect(() => {
        if (!isTyping.current || search === appliedSearch) {
            return undefined;
        }

        // Below the length the suggestion endpoint will answer for, a live
        // reload is all cost: the trigram indexes cannot serve a pattern with
        // fewer than three non-wildcard characters, so `%j%` sequentially scans
        // every event's title, venue and description.
        if (search.trim().length > 0 && search.trim().length < MIN_LIVE_SEARCH_LENGTH) {
            return undefined;
        }

        const timer = setTimeout(() => {
            // Re-checked here, not only at setup. `isTyping` is a ref, so a
            // handler setting it to false cannot re-run this effect and cannot
            // reach the cleanup below — the timer it was meant to disarm would
            // otherwise still fire and navigate with a stale closure, undoing
            // the filter the user just clicked.
            if (!isTyping.current) {
                return;
            }

            isTyping.current = false;
            applyFilters({}, { live: true });
        }, SEARCH_DEBOUNCE_MS);

        return () => clearTimeout(timer);
    }, [search, appliedSearch, applyFilters]);

    // Every deliberate action disarms the debounce, so a keystroke still in
    // flight cannot fire afterwards and re-navigate with stale filters.
    const handleSearch = (e) => {
        e.preventDefault();
        isTyping.current = false;
        applyFilters({});
    };

    const handleCategoryFilter = (value) => {
        isTyping.current = false;
        applyFilters({ category: activeCategory === value ? null : value });
    };

    const handleDateChange = (value) => {
        isTyping.current = false;
        applyFilters({ date: value || null });
    };

    // Tag and venue are exact facets, so they become filters rather than free
    // text. Searching for them instead would match events that merely mention
    // the word — and, with the search index down, would not look at `tags` at
    // all, so the dropdown would suggest a tag that returned nothing.
    const handleTagSelect = useCallback((tag) => {
        isTyping.current = false;
        setSearch('');
        applyFilters({ search: null, tag });
    }, [applyFilters]);

    const handleVenueSelect = useCallback((venue) => {
        isTyping.current = false;
        setSearch('');
        applyFilters({ search: null, venue });
    }, [applyFilters]);

    const handleCategorySelect = useCallback((value) => {
        isTyping.current = false;
        setSearch('');
        applyFilters({ search: null, category: value });
    }, [applyFilters]);

    const handleCommit = useCallback(
        (term) => {
            isTyping.current = false;
            applyFilters({ search: term || null });
        },
        [applyFilters]
    );

    const handlePageChange = (url) => {
        if (url) {
            router.get(url, {}, { preserveState: true, preserveScroll: true });
        }
    };

    return (
        <AppLayout title="Evenimente">
            <Head title="Evenimente" />

            {/* Guests see the same list read-only — nudge them toward a profile */}
            {isGuest && (
                <div className="mb-6 flex flex-wrap items-center justify-between gap-4 rounded-lg border border-[#FF5733]/20 bg-[#FF5733]/5 px-5 py-4">
                    <p className="text-sm text-gray-700">
                        Vezi tot ce se întâmplă în oraș. Cu un cont, lista se rescrie
                        după gusturile tale.
                    </p>
                    <Link
                        href="/register"
                        className="inline-flex min-h-11 items-center whitespace-nowrap rounded-full bg-[#FF5733] px-5 py-2.5 text-sm font-semibold text-white transition-opacity hover:opacity-90 sm:min-h-0"
                    >
                        Înregistrează-te
                    </Link>
                </div>
            )}

            {/* Search bar. Results follow the typing, but the form and its
                button stay: they let Enter commit immediately rather than
                waiting out the debounce, and give the action a visible target
                on touch keyboards. */}
            <form onSubmit={handleSearch} className="mb-4">
                <div className="flex gap-2">
                    <SearchAutocomplete
                        value={search}
                        onChange={handleSearchChange}
                        onCommit={handleCommit}
                        onSelectCategory={handleCategorySelect}
                        onSelectTag={handleTagSelect}
                        onSelectVenue={handleVenueSelect}
                        busy={searching}
                    />
                    <Button type="submit">Caută</Button>
                </div>
            </form>

            {/* Active facet filters. The autocomplete is the only thing that
                sets these, and without a visible, removable chip they would be
                invisible and permanent: a leftover venue turns the next search
                into "no events match", blaming the keywords for a filter the
                reader cannot see. */}
            {(filters.tag || filters.venue) && (
                <div className="mb-4 flex flex-wrap items-center gap-2">
                    {filters.tag && (
                        <button
                            type="button"
                            onClick={() => applyFilters({ tag: null })}
                            className="inline-flex min-h-11 items-center gap-2 rounded-full bg-indigo-50 px-4 py-1.5 text-sm font-medium text-indigo-700 transition-colors hover:bg-indigo-100 sm:min-h-0"
                        >
                            Etichetă: {filters.tag}
                            <X className="h-3.5 w-3.5" aria-hidden="true" />
                            <span className="sr-only">Elimină filtrul de etichetă</span>
                        </button>
                    )}
                    {filters.venue && (
                        <button
                            type="button"
                            onClick={() => applyFilters({ venue: null })}
                            className="inline-flex min-h-11 items-center gap-2 rounded-full bg-indigo-50 px-4 py-1.5 text-sm font-medium text-indigo-700 transition-colors hover:bg-indigo-100 sm:min-h-0"
                        >
                            Locație: {filters.venue}
                            <X className="h-3.5 w-3.5" aria-hidden="true" />
                            <span className="sr-only">Elimină filtrul de locație</span>
                        </button>
                    )}
                </div>
            )}

            {/* Date filter */}
            <div className="flex flex-wrap items-center gap-2 mb-6">
                <label htmlFor="event-date" className="text-sm font-medium text-gray-700">
                    Dată:
                </label>
                <Input
                    id="event-date"
                    type="date"
                    min={today}
                    value={activeDate}
                    onChange={(e) => handleDateChange(e.target.value)}
                    className="w-full sm:w-auto"
                />
                {activeDate && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="min-h-11 sm:min-h-0"
                        onClick={() => handleDateChange(null)}
                    >
                        Toate datele
                    </Button>
                )}
            </div>

            {/* Category filter chips */}
            <div className="flex flex-wrap gap-2 mb-6">
                {CATEGORIES.map(({ value, label }) => (
                    <button
                        key={value}
                        onClick={() => handleCategoryFilter(value)}
                        className={cn(
                            'inline-flex min-h-11 items-center rounded-full px-4 py-1.5 text-sm font-medium transition-colors sm:min-h-0',
                            activeCategory === value
                                ? 'bg-indigo-600 text-white'
                                : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                        )}
                    >
                        {label}
                    </button>
                ))}
            </div>

            {/* Event grid. Dimmed rather than replaced while the next set
                loads — the previous results stay readable, and a skeleton
                would flash on every keystroke. */}
            <div
                className={cn('transition-opacity', searching && 'opacity-50')}
                aria-busy={searching}
            >
                <EventList
                    events={Array.isArray(eventData) ? eventData : []}
                    emptyMessage="Niciun eveniment nu corespunde căutării. Încearcă alte cuvinte cheie sau filtre."
                    showReactions={!isGuest}
                />
            </div>

            {/* Pagination */}
            {events.meta?.last_page > 1 && (
                <div className="flex items-center justify-center gap-2 mt-8">
                    <Button
                        variant="outline"
                        size="sm"
                        className="min-h-11 sm:min-h-0"
                        disabled={!events.links?.prev}
                        onClick={() => handlePageChange(events.links?.prev)}
                    >
                        Înapoi
                    </Button>
                    <span className="text-sm text-gray-500">
                        Pagina {events.meta.current_page} din {events.meta.last_page}
                    </span>
                    <Button
                        variant="outline"
                        size="sm"
                        className="min-h-11 sm:min-h-0"
                        disabled={!events.links?.next}
                        onClick={() => handlePageChange(events.links?.next)}
                    >
                        Înainte
                    </Button>
                </div>
            )}
        </AppLayout>
    );
}
