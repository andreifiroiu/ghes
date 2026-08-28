import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import Pagination from '@/Components/Pagination';
import { Card, CardContent } from '@/Components/ui/Card';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { Select } from '@/Components/ui/Select';
import { Badge } from '@/Components/ui/Badge';

/**
 * @param {Object} props
 * @param {{data: Array<Object>, meta?: Object, links?: Array}} props.events
 * @param {Object} props.filters
 * @param {Array<string>} props.categories
 */
export default function EventsIndex({ events, filters = {}, categories = [] }) {
    const [search, setSearch] = useState(filters.search || '');
    const [category, setCategory] = useState(filters.category || '');
    const [status, setStatus] = useState(filters.status || '');

    const applyFilters = (overrides = {}) => {
        router.get('/admin/events', {
            search, category, status, ...overrides,
        }, { preserveState: true, replace: true });
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

    return (
        <AdminLayout title="Events">
            <Head title="Admin — Events" />

            <form
                onSubmit={(e) => { e.preventDefault(); applyFilters(); }}
                className="flex flex-wrap gap-2 mb-4"
            >
                <Input
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Search title…"
                    className="max-w-xs"
                />
                <Select value={category} onChange={(e) => { setCategory(e.target.value); applyFilters({ category: e.target.value }); }}>
                    <option value="">All categories</option>
                    {categories.map((c) => <option key={c} value={c}>{c}</option>)}
                </Select>
                <Select value={status} onChange={(e) => { setStatus(e.target.value); applyFilters({ status: e.target.value }); }}>
                    <option value="">All statuses</option>
                    <option value="hidden">Hidden</option>
                    <option value="unclassified">Unclassified</option>
                    <option value="ungeocoded">Ungeocoded</option>
                </Select>
                <Button type="submit">Search</Button>
            </form>

            <Card>
                <CardContent className="p-0 overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="bg-gray-50 text-left text-gray-500">
                            <tr>
                                <th className="px-4 py-2">Title</th>
                                <th className="px-4 py-2">Category</th>
                                <th className="px-4 py-2">City</th>
                                <th className="px-4 py-2">Pop.</th>
                                <th className="px-4 py-2">Status</th>
                                <th className="px-4 py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 && (
                                <tr><td colSpan={6} className="px-4 py-6 text-center text-gray-400">No events.</td></tr>
                            )}
                            {rows.map((event) => (
                                <tr key={event.id} className="border-t border-gray-100">
                                    <td className="px-4 py-2 font-medium">{event.title}</td>
                                    <td className="px-4 py-2">{event.category}</td>
                                    <td className="px-4 py-2">{event.city}</td>
                                    <td className="px-4 py-2">{event.popularity_score}</td>
                                    <td className="px-4 py-2 space-x-1">
                                        {event.is_hidden && <Badge>Hidden</Badge>}
                                        {!event.is_classified && <Badge>Unclassified</Badge>}
                                    </td>
                                    <td className="px-4 py-2 text-right space-x-2 whitespace-nowrap">
                                        <Link href={`/admin/events/${event.id}/edit`} className="text-[#FF5733] hover:underline">Edit</Link>
                                        <button onClick={() => post(`/admin/events/${event.id}/hide`)} className="text-gray-600 hover:underline">
                                            {event.is_hidden ? 'Unhide' : 'Hide'}
                                        </button>
                                        <button onClick={() => post(`/admin/events/${event.id}/feature`)} className="text-gray-600 hover:underline">Boost</button>
                                        <button onClick={() => reprocess(event.id, 'classify')} className="text-gray-600 hover:underline">Re-classify</button>
                                        <button onClick={() => destroy(event.id)} className="text-red-600 hover:underline">Delete</button>
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
