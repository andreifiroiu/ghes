import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
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
                    className="w-auto"
                />
                {activeDate && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
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
                            'inline-flex items-center rounded-full px-3 py-1.5 text-sm font-medium transition-colors',
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
            />

            {/* Pagination */}
            {events.meta?.last_page > 1 && (
                <div className="flex items-center justify-center gap-2 mt-8">
                    <Button
                        variant="outline"
                        size="sm"
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
