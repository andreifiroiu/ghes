import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Card, CardContent } from '@/Components/ui/Card';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { Select } from '@/Components/ui/Select';
import { Badge } from '@/Components/ui/Badge';
import { formatEventDate } from '@/lib/dates';

/**
 * Review clusters of events that look like the same real-world event and fold
 * them into one canonical row.
 *
 * @param {Object} props
 * @param {Array<{key: string, reason: string, score: number, events: Array<Object>}>} props.groups
 * @param {Object} props.filters
 * @param {Array<string>} props.cities
 * @param {number} props.limit
 * @param {boolean} props.truncated
 */
export default function Duplicates({ groups = [], filters = {}, cities = [], limit = 25, truncated = false }) {
    const [form, setForm] = useState({
        fuzzy: Boolean(filters.fuzzy),
        city: filters.city || '',
        date_from: filters.date_from || '',
        date_to: filters.date_to || '',
    });

    // Per-group review state, keyed by group key so it survives a re-render.
    // Absent entries fall back to "keep the suggested row, merge everything".
    const [choices, setChoices] = useState({});

    const applyFilters = (overrides = {}) => {
        const next = { ...form, ...overrides };
        setForm(next);
        router.get('/admin/events/duplicates', next, { preserveState: true, replace: true });
    };

    const choiceFor = (group) => choices[group.key] || { canonical: group.events[0].id, excluded: [] };

    const setChoice = (group, patch) =>
        setChoices((current) => ({ ...current, [group.key]: { ...choiceFor(group), ...patch } }));

    const toggleExcluded = (group, id) => {
        const { excluded } = choiceFor(group);
        setChoice(group, {
            excluded: excluded.includes(id) ? excluded.filter((v) => v !== id) : [...excluded, id],
        });
    };

    const mergeGroup = (group) => {
        const { canonical, excluded } = choiceFor(group);
        const duplicates = group.events
            .map((event) => event.id)
            .filter((id) => id !== canonical && !excluded.includes(id));

        if (duplicates.length === 0) {
            return;
        }

        const kept = group.events.find((event) => event.id === canonical);

        if (!confirm(`Merge ${duplicates.length} event(s) into "${kept?.title}"? This cannot be undone.`)) {
            return;
        }

        router.post('/admin/events/merge', { canonical_id: canonical, duplicate_ids: duplicates }, {
            preserveScroll: true,
        });
    };

    return (
        <AdminLayout title="Duplicate events">
            <Head title="Admin — Duplicates" />

            <div className="mb-4 flex flex-wrap items-end gap-2">
                <Select
                    value={form.city}
                    onChange={(e) => applyFilters({ city: e.target.value })}
                    className="max-w-xs"
                    aria-label="City"
                >
                    <option value="">All cities</option>
                    {cities.map((city) => <option key={city} value={city}>{city}</option>)}
                </Select>
                <label className="flex items-center gap-2 text-sm text-gray-600">
                    <span>From</span>
                    <Input
                        type="date"
                        value={form.date_from}
                        onChange={(e) => applyFilters({ date_from: e.target.value })}
                    />
                </label>
                <label className="flex items-center gap-2 text-sm text-gray-600">
                    <span>To</span>
                    <Input
                        type="date"
                        value={form.date_to}
                        onChange={(e) => applyFilters({ date_to: e.target.value })}
                    />
                </label>
                <label className="flex min-h-11 items-center gap-2 text-sm text-gray-700 sm:min-h-10">
                    <input
                        type="checkbox"
                        className="h-4 w-4"
                        checked={form.fuzzy}
                        onChange={(e) => applyFilters({ fuzzy: e.target.checked })}
                    />
                    Include likely matches (scored)
                </label>
                <Link href="/admin/events" className="text-sm text-[#FF5733] hover:underline">
                    ← Back to events
                </Link>
            </div>

            <p className="mb-4 text-sm text-gray-500">
                Exact matches share a title, city and date. Scored matches are close enough that the
                nightly <code>--fuzzy</code> pass would merge them. Merging moves sources, reactions and
                saves onto the row you keep; the other rows stay in the database so old links resolve.
            </p>

            {groups.length === 0 && (
                <Card>
                    <CardContent className="py-10 text-center text-gray-400">
                        No duplicates found{form.fuzzy ? '' : ' — try including likely matches'}.
                    </CardContent>
                </Card>
            )}

            <div className="space-y-4">
                {groups.map((group) => {
                    const { canonical, excluded } = choiceFor(group);
                    const mergeable = group.events.filter(
                        (event) => event.id !== canonical && !excluded.includes(event.id)
                    ).length;

                    return (
                        <Card key={group.key}>
                            <CardContent className="p-0">
                                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-3">
                                    <div className="flex items-center gap-2 text-sm">
                                        <Badge variant={group.reason === 'match_key' ? 'default' : 'secondary'}>
                                            {group.reason === 'match_key' ? 'Exact match' : `Scored ${group.score}`}
                                        </Badge>
                                        <span className="text-gray-500">{group.events.length} events</span>
                                    </div>
                                    <Button type="button" onClick={() => mergeGroup(group)} disabled={mergeable === 0}>
                                        Merge {mergeable} into selected
                                    </Button>
                                </div>

                                <div className="overflow-x-auto">
                                    <table className="w-full min-w-[760px] text-sm">
                                        <thead className="bg-gray-50 text-left text-gray-500">
                                            <tr>
                                                <th className="w-16 px-4 py-2">Keep</th>
                                                <th className="w-16 px-4 py-2">Merge</th>
                                                <th className="px-4 py-2">Title</th>
                                                <th className="px-4 py-2">Date</th>
                                                <th className="px-4 py-2">Venue</th>
                                                <th className="px-4 py-2">Source</th>
                                                <th className="px-4 py-2 text-right">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {group.events.map((event) => (
                                                <tr key={event.id} className="border-t border-gray-100">
                                                    <td className="px-4 py-2">
                                                        <input
                                                            type="radio"
                                                            className="h-4 w-4"
                                                            name={`canonical-${group.key}`}
                                                            checked={canonical === event.id}
                                                            onChange={() => setChoice(group, { canonical: event.id })}
                                                            aria-label={`Keep ${event.title}`}
                                                        />
                                                    </td>
                                                    <td className="px-4 py-2">
                                                        <input
                                                            type="checkbox"
                                                            className="h-4 w-4"
                                                            disabled={canonical === event.id}
                                                            checked={canonical !== event.id && !excluded.includes(event.id)}
                                                            onChange={() => toggleExcluded(group, event.id)}
                                                            aria-label={`Merge ${event.title}`}
                                                        />
                                                    </td>
                                                    <td className="px-4 py-2 font-medium">{event.title}</td>
                                                    <td className="px-4 py-2 whitespace-nowrap text-gray-600">
                                                        {formatEventDate(event.starts_at)}
                                                    </td>
                                                    <td className="px-4 py-2 text-gray-600">{event.venue || '—'}</td>
                                                    <td className="px-4 py-2 text-gray-600">{event.source}</td>
                                                    <td className="px-4 py-2 text-right space-x-2 whitespace-nowrap">
                                                        <Link
                                                            href={`/admin/events/${event.id}/edit`}
                                                            className="text-[#FF5733] hover:underline"
                                                        >
                                                            Edit
                                                        </Link>
                                                        {event.source_url && (
                                                            <a
                                                                href={event.source_url}
                                                                target="_blank"
                                                                rel="noreferrer"
                                                                className="text-gray-600 hover:underline"
                                                            >
                                                                Source
                                                            </a>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </CardContent>
                        </Card>
                    );
                })}
            </div>

            {truncated && (
                <p className="mt-4 text-sm text-gray-500">
                    Showing the first {limit} groups. Merge these, then reload for more.
                </p>
            )}
        </AdminLayout>
    );
}
