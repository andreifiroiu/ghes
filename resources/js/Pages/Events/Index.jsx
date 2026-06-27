import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import EventList from '@/Components/Events/EventList';
import { Input } from '@/Components/ui/Input';
import { Button } from '@/Components/ui/Button';
import { cn } from '@/lib/utils';

const allCategories = [
    { value: 'music', label: 'Muzică' },
    { value: 'technology', label: 'Tech' },
    { value: 'sports', label: 'Sport' },
    { value: 'arts', label: 'Artă' },
    { value: 'food', label: 'Gastronomie' },
    { value: 'nightlife', label: 'Viața de noapte' },
    { value: 'business', label: 'Business' },
    { value: 'health', label: 'Sănătate' },
    { value: 'education', label: 'Educație' },
    { value: 'family', label: 'Familie' },
    { value: 'community', label: 'Comunitate' },
    { value: 'film', label: 'Film' },
    { value: 'literature', label: 'Literatură' },
    { value: 'other', label: 'Altele' },
];

/**
 * @param {Object} props
 * @param {Object} props.events - Paginated events object
 * @param {Array<Object>} props.events.data
 * @param {Object} props.events.links
 * @param {Object} props.events.meta
 * @param {number} props.events.current_page
 * @param {number} props.events.last_page
 * @param {string|null} props.events.next_page_url
 * @param {string|null} props.events.prev_page_url
 * @param {string} [props.filters.search]
 * @param {string} [props.filters.category]
 */
export default function Index({ events = {}, filters = {} }) {
    const eventData = events.data || events;
    const [search, setSearch] = useState(filters.search || '');
    const activeCategory = filters.category || null;

    const handleSearch = (e) => {
        e.preventDefault();
        router.get(
            '/events',
            { search, category: activeCategory },
            { preserveState: true, preserveScroll: true }
        );
    };

    const handleCategoryFilter = (value) => {
        const newCategory = activeCategory === value ? null : value;
        router.get(
            '/events',
            { search, category: newCategory },
            { preserveState: true, preserveScroll: true }
        );
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
            <form onSubmit={handleSearch} className="mb-6">
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

            {/* Category filter chips */}
            <div className="flex flex-wrap gap-2 mb-6">
                {allCategories.map(({ value, label }) => (
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
            {events.last_page > 1 && (
                <div className="flex items-center justify-center gap-2 mt-8">
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={!events.prev_page_url}
                        onClick={() => handlePageChange(events.prev_page_url)}
                    >
                        Înapoi
                    </Button>
                    <span className="text-sm text-gray-500">
                        Pagina {events.current_page} din {events.last_page}
                    </span>
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={!events.next_page_url}
                        onClick={() => handlePageChange(events.next_page_url)}
                    >
                        Înainte
                    </Button>
                </div>
            )}
        </AppLayout>
    );
}
