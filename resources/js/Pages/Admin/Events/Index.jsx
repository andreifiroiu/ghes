import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import Pagination from '@/Components/Pagination';
import { Card, CardContent } from '@/Components/ui/Card';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { Select } from '@/Components/ui/Select';
import { Badge } from '@/Components/ui/Badge';
import { formatEventDate } from '@/lib/dates';

const STATUSES = [
    { value: '', label: 'All statuses' },
    { value: 'hidden', label: 'Hidden' },
    { value: 'unclassified', label: 'Unclassified' },
    { value: 'ungeocoded', label: 'Ungeocoded' },
    { value: 'merged', label: 'Merged duplicates' },
];

/**
 * @param {Object} props
 * @param {{data: Array<Object>, meta?: Object, links?: Array}} props.events
 * @param {Object} props.filters
 * @param {Array<string>} props.categories
 * @param {Array<string>} props.sources
 * @param {Array<string>} props.cities
 */
export default function EventsIndex({ events, filters = {}, categories = [], sources = [], cities = [] }) {
    const [form, setForm] = useState({
        search: filters.search || '',
        category: filters.category || '',
        city: filters.city || '',
        source: filters.source || '',
        status: filters.status || '',
        date_from: filters.date_from || '',
        date_to: filters.date_to || '',
    });
    const [selected, setSelected] = useState([]);
    const [canonicalId, setCanonicalId] = useState('');

    const sort = filters.sort || 'created_at';
    const direction = filters.direction || 'desc';

    const applyFilters = (overrides = {}) => {
        const next = { ...form, ...overrides };
        setForm(next);
        router.get('/admin/events', { ...next, sort, direction }, { preserveState: true, replace: true });
    };

    const clearFilters = () => {
        const empty = { search: '', category: '', city: '', source: '', status: '', date_from: '', date_to: '' };
        setForm(empty);
        router.get('/admin/events', empty, { preserveState: true, replace: true });
    };

    // Clicking the active column flips direction; a new column starts descending.
    const sortBy = (column) => {
        const nextDirection = sort === column && direction === 'desc' ? 'asc' : 'desc';
        router.get('/admin/events', { ...form, sort: column, direction: nextDirection }, {
            preserveState: true, replace: true,
        });
    };

    const post = (url) => router.post(url, {}, { preserveScroll: true });
    const reprocess = (id, action) =>
        router.post(`/admin/events/${id}/reprocess`, { action }, { preserveScroll: true });
    const destroy = (id) => {
        if (confirm('Delete this event permanently?')) {
            router.delete(`/admin/events/${id}`, { preserveScroll: true });
        }
    };

    const rows = events.data || [];
    const selectedRows = rows.filter((event) => selected.includes(event.id));

    const toggleSelected = (id) => {
        const next = selected.includes(id) ? selected.filter((v) => v !== id) : [...selected, id];

        setSelected(next);

        // Keep the event to keep pointing at something still selected.
        if (!next.includes(canonicalId)) {
            setCanonicalId(next[0] || '');
        }
    };

    const clearSelection = () => {
        setSelected([]);
        setCanonicalId('');
    };

    const mergeSelected = () => {
        const keep = canonicalId || selected[0];
        const duplicates = selected.filter((id) => id !== keep);

        if (duplicates.length === 0) {
            return;
        }

        const kept = rows.find((event) => event.id === keep);

        if (!confirm(`Merge ${duplicates.length} event(s) into "${kept?.title}"? This cannot be undone.`)) {
            return;
        }

        router.post('/admin/events/merge', { canonical_id: keep, duplicate_ids: duplicates }, {
            preserveScroll: true,
            onSuccess: clearSelection,
        });
    };

    const sortIndicator = (column) => (sort === column ? (direction === 'asc' ? ' ↑' : ' ↓') : '');

    // A plain render helper rather than a nested component, so React does not
    // remount the header cells on every keystroke in the filter form.
    const sortableHeader = (column, label) => (
        <th className="px-4 py-2">
            <button
                type="button"
                onClick={() => sortBy(column)}
                className="font-medium text-gray-500 hover:text-gray-900"
            >
                {label}{sortIndicator(column)}
            </button>
        </th>
    );

    return (
        <AdminLayout title="Events">
            <Head title="Admin — Events" />

            <form
                onSubmit={(e) => { e.preventDefault(); applyFilters(); }}
                className="mb-4 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4"
            >
                <Input
                    value={form.search}
                    onChange={(e) => setForm({ ...form, search: e.target.value })}
                    placeholder="Search title…"
                />
                <Select
                    value={form.category}
                    onChange={(e) => applyFilters({ category: e.target.value })}
                    aria-label="Category"
                >
                    <option value="">All categories</option>
                    {categories.map((c) => <option key={c} value={c}>{c}</option>)}
                </Select>
                <Select
                    value={form.city}
                    onChange={(e) => applyFilters({ city: e.target.value })}
                    aria-label="City"
                >
                    <option value="">All cities</option>
                    {cities.map((city) => <option key={city} value={city}>{city}</option>)}
                </Select>
                <Select
                    value={form.source}
                    onChange={(e) => applyFilters({ source: e.target.value })}
                    aria-label="Source"
                >
                    <option value="">All sources</option>
                    {sources.map((s) => <option key={s} value={s}>{s}</option>)}
                </Select>
                <Select
                    value={form.status}
                    onChange={(e) => applyFilters({ status: e.target.value })}
                    aria-label="Status"
                >
                    {STATUSES.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                </Select>

                <label className="flex items-center gap-2 text-sm text-gray-600">
                    <span className="w-10 shrink-0">From</span>
                    <Input
                        type="date"
                        value={form.date_from}
                        onChange={(e) => applyFilters({ date_from: e.target.value })}
                    />
                </label>
                <label className="flex items-center gap-2 text-sm text-gray-600">
                    <span className="w-10 shrink-0">To</span>
                    <Input
                        type="date"
                        value={form.date_to}
                        onChange={(e) => applyFilters({ date_to: e.target.value })}
                    />
                </label>

                <div className="flex gap-2 lg:col-span-2">
                    <Button type="submit">Search</Button>
                    <Button type="button" variant="outline" onClick={clearFilters}>Reset</Button>
                    <Link
                        href="/admin/events/duplicates"
                        className="inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 sm:min-h-10"
                    >
                        Find duplicates
                    </Link>
                </div>
            </form>

            {selected.length > 0 && (
                <div className="mb-4 flex flex-wrap items-center gap-2 rounded-md border border-[#FF5733]/30 bg-[#FF5733]/5 px-4 py-3 text-sm">
                    <span className="font-medium">{selected.length} selected</span>
                    <span className="text-gray-500">keep</span>
                    <Select
                        value={canonicalId}
                        onChange={(e) => setCanonicalId(e.target.value)}
                        className="max-w-xs"
                        aria-label="Event to keep"
                    >
                        {selectedRows.map((event) => (
                            <option key={event.id} value={event.id}>
                                {event.title} ({event.source})
                            </option>
                        ))}
                    </Select>
                    <Button type="button" onClick={mergeSelected} disabled={selected.length < 2}>
                        Merge
                    </Button>
                    <button type="button" onClick={clearSelection} className="text-gray-600 hover:underline">
                        Clear
                    </button>
                    {selected.length < 2 && (
                        <span className="text-gray-500">Select at least two events to merge.</span>
                    )}
                </div>
            )}

            <Card>
                <CardContent className="p-0 overflow-x-auto">
                    <table className="w-full min-w-[900px] text-sm">
                        <thead className="bg-gray-50 text-left text-gray-500">
                            <tr>
                                <th className="w-10 px-4 py-2"><span className="sr-only">Select</span></th>
                                {sortableHeader('title', 'Title')}
                                {sortableHeader('starts_at', 'Date')}
                                <th className="px-4 py-2">Source</th>
                                <th className="px-4 py-2">Category</th>
                                <th className="px-4 py-2">City</th>
                                {sortableHeader('popularity_score', 'Pop.')}
                                <th className="px-4 py-2">Status</th>
                                <th className="px-4 py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 && (
                                <tr><td colSpan={9} className="px-4 py-6 text-center text-gray-400">No events.</td></tr>
                            )}
                            {rows.map((event) => (
                                <tr key={event.id} className="border-t border-gray-100">
                                    <td className="px-4 py-2">
                                        <input
                                            type="checkbox"
                                            className="h-4 w-4"
                                            checked={selected.includes(event.id)}
                                            onChange={() => toggleSelected(event.id)}
                                            aria-label={`Select ${event.title}`}
                                        />
                                    </td>
                                    <td className="px-4 py-2 font-medium">{event.title}</td>
                                    <td className="px-4 py-2 whitespace-nowrap text-gray-600">
                                        {formatEventDate(event.starts_at)}
                                    </td>
                                    <td className="px-4 py-2 text-gray-600">
                                        {event.source}
                                        {event.sources_count > 1 && (
                                            <span className="ml-1 text-xs text-gray-400">+{event.sources_count - 1}</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-2">{event.category}</td>
                                    <td className="px-4 py-2">{event.city}</td>
                                    <td className="px-4 py-2">{event.popularity_score}</td>
                                    <td className="px-4 py-2 space-x-1">
                                        {event.is_hidden && <Badge>Hidden</Badge>}
                                        {!event.is_classified && <Badge>Unclassified</Badge>}
                                        {event.merged_into_id && <Badge variant="secondary">Merged</Badge>}
                                    </td>
                                    <td className="px-4 py-2 text-right space-x-2 whitespace-nowrap">
                                        <Link href={`/admin/events/${event.id}/edit`} className="text-[#FF5733] hover:underline">Edit</Link>
                                        <button onClick={() => post(`/admin/events/${event.id}/hide`)} className="inline-flex min-h-11 items-center text-gray-600 hover:underline sm:min-h-0">
                                            {event.is_hidden ? 'Unhide' : 'Hide'}
                                        </button>
                                        <button onClick={() => post(`/admin/events/${event.id}/feature`)} className="inline-flex min-h-11 items-center text-gray-600 hover:underline sm:min-h-0">Boost</button>
                                        <button onClick={() => reprocess(event.id, 'classify')} className="inline-flex min-h-11 items-center text-gray-600 hover:underline sm:min-h-0">Re-classify</button>
                                        <button onClick={() => destroy(event.id)} className="inline-flex min-h-11 items-center text-red-600 hover:underline sm:min-h-0">Delete</button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <Pagination paginator={events} />
        </AdminLayout>
    );
}
