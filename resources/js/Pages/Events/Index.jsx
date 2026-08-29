import { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import EventList from '@/Components/Events/EventList';
import { Input } from '@/Components/ui/Input';
import { Button } from '@/Components/ui/Button';
import { cn } from '@/lib/utils';
import { CATEGORIES } from '@/lib/categories';

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
    const activeCategory = filters.category || null;
    const activeDate = filters.date || '';
    const today = new Date().toISOString().slice(0, 10);

    const applyFilters = (overrides) => {
        router.get(
            '/events',
            {
                search,
                category: activeCategory,
                date: activeDate,
                ...overrides,
            },
            { preserveState: true, preserveScroll: true }
        );
    };

    const handleSearch = (e) => {
        e.preventDefault();
        applyFilters({});
    };

    const handleCategoryFilter = (value) => {
        applyFilters({ category: activeCategory === value ? null : value });
    };

    const handleDateChange = (value) => {
        applyFilters({ date: value || null });
    };

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

            {/* Search bar */}
            <form onSubmit={handleSearch} className="mb-4">
                <div className="flex gap-2">
                    <Input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Caută evenimente..."
                        className="flex-1"
                    />
                    <Button type="submit">Caută</Button>
                </div>
            </form>

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

            {/* Event grid */}
            <EventList
                events={Array.isArray(eventData) ? eventData : []}
                emptyMessage="Niciun eveniment nu corespunde căutării. Încearcă alte cuvinte cheie sau filtre."
                showReactions={!isGuest}
            />

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
