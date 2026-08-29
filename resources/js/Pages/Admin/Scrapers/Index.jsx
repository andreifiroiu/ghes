import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Button } from '@/Components/ui/Button';
import { Select } from '@/Components/ui/Select';
import { Badge } from '@/Components/ui/Badge';
import Pagination from '@/Components/Pagination';
import RunStatusBadge from '@/Components/Admin/RunStatusBadge';
import { formatDuration, formatRunTime } from '@/lib/dates';

/**
 * @param {Object} props
 * @param {{data: Array<Object>}} props.runs
 * @param {Array<string>} props.cities
 * @param {Object<string, Array<{adapter: string, enabled: boolean}>>} props.adapters
 *   Sources configured per city — keyed by city so we never offer a source that
 *   city has not configured.
 * @param {Array<Object>} props.sources
 *   Every configured adapter+city pair with its latest run.
 */
export default function Scrapers({ runs, cities = [], adapters = {}, sources = [] }) {
    const [city, setCity] = useState('');
    const [source, setSource] = useState('');

    const citySources = adapters[city] || [];

    const handleCityChange = (value) => {
        setCity(value);
        setSource('');
    };

    const run = (e) => {
        e.preventDefault();
        router.post('/admin/scrapers/run', { city: city || null, source: source || null }, { preserveScroll: true });
    };

    const rows = runs.data || [];

    return (
        <AdminLayout title="Scrapers">
            <Head title="Admin — Scrapers" />

            <Card className="mb-6 max-w-2xl">
                <CardHeader><CardTitle className="text-lg">Run scrapers</CardTitle></CardHeader>
                <CardContent>
                    <form onSubmit={run} className="flex flex-wrap gap-2 items-end [&>div]:w-full [&>div]:sm:w-auto">
                        <div>
                            <Select value={city} onChange={(e) => handleCityChange(e.target.value)}>
                                <option value="">All cities</option>
                                {cities.map((c) => <option key={c} value={c}>{c}</option>)}
                            </Select>
                        </div>
                        <div>
                            <Select value={source} onChange={(e) => setSource(e.target.value)} disabled={!city}>
                                <option value="">{city ? 'All sources' : 'Pick a city first'}</option>
                                {citySources.map(({ adapter, enabled }) => (
                                    <option key={adapter} value={adapter}>
                                        {enabled ? adapter : `${adapter} (disabled)`}
                                    </option>
                                ))}
                            </Select>
                        </div>
                        <Button type="submit">Queue run</Button>
                    </form>
                    <p className="mt-3 text-xs text-gray-500">
                        Queuing dispatches a job. Nothing appears in the log below until a worker
                        picks it up, so an idle <code>scraping</code> queue looks like nothing happened.
                    </p>
                </CardContent>
            </Card>

            <Card className="mb-6">
                <CardHeader><CardTitle className="text-lg">Sources</CardTitle></CardHeader>
                <CardContent className="p-0 overflow-x-auto">
                    <table className="w-full min-w-[720px] text-sm">
                        <thead className="bg-gray-50 text-left text-gray-500">
                            <tr>
                                <th className="px-4 py-2">Source</th>
                                <th className="px-4 py-2">City</th>
                                <th className="px-4 py-2">Every</th>
                                <th className="px-4 py-2">Last run</th>
                                <th className="px-4 py-2">When</th>
                                <th className="px-4 py-2">Found</th>
                                <th className="px-4 py-2">New</th>
                            </tr>
                        </thead>
                        <tbody>
                            {sources.length === 0 && (
                                <tr><td colSpan={7} className="px-4 py-6 text-center text-gray-400">No sources configured.</td></tr>
                            )}
                            {sources.map((s) => (
                                <tr key={`${s.city}-${s.adapter}`} className="border-t border-gray-100">
                                    <td className="px-4 py-2">
                                        <Link
                                            href={`/admin/scrapers/${s.city}/${s.adapter}`}
                                            className="font-medium text-[#FF5733] hover:underline"
                                        >
                                            {s.adapter}
                                        </Link>
                                        {!s.enabled && (
                                            <Badge variant="outline" className="ml-2">disabled</Badge>
                                        )}
                                    </td>
                                    <td className="px-4 py-2">{s.city_label}</td>
                                    <td className="px-4 py-2 text-gray-500">
                                        {s.interval_hours ? `${s.interval_hours}h` : '—'}
                                    </td>
                                    <td className="px-4 py-2"><RunStatusBadge status={s.last_run?.status} /></td>
                                    <td className="px-4 py-2 text-gray-500">
                                        {formatRunTime(s.last_run?.started_at)}
                                    </td>
                                    <td className="px-4 py-2">{s.last_run?.events_found ?? '—'}</td>
                                    <td className="px-4 py-2">{s.last_run?.events_created ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <Card>
                <CardHeader><CardTitle className="text-lg">Recent runs</CardTitle></CardHeader>
                <CardContent className="p-0 overflow-x-auto">
                    <table className="w-full min-w-[720px] text-sm">
                        <thead className="bg-gray-50 text-left text-gray-500">
                            <tr>
                                <th className="px-4 py-2">Source</th>
                                <th className="px-4 py-2">City</th>
                                <th className="px-4 py-2">Status</th>
                                <th className="px-4 py-2">Found</th>
                                <th className="px-4 py-2">New</th>
                                <th className="px-4 py-2">Started</th>
                                <th className="px-4 py-2">Duration</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 && (
                                <tr><td colSpan={7} className="px-4 py-6 text-center text-gray-400">No runs yet.</td></tr>
                            )}
                            {rows.map((r) => (
                                <tr key={r.id} className="border-t border-gray-100">
                                    <td className="px-4 py-2">
                                        {r.city ? (
                                            <Link
                                                href={`/admin/scrapers/${r.city}/${r.source}`}
                                                className="text-[#FF5733] hover:underline"
                                            >
                                                {r.source}
                                            </Link>
                                        ) : r.source}
                                    </td>
                                    <td className="px-4 py-2">{r.city ?? '—'}</td>
                                    <td className="px-4 py-2"><RunStatusBadge status={r.status} /></td>
                                    <td className="px-4 py-2">{r.events_found ?? 0}</td>
                                    <td className="px-4 py-2">{r.events_created ?? 0}</td>
                                    <td className="px-4 py-2 text-gray-500">{formatRunTime(r.started_at)}</td>
                                    <td className="px-4 py-2 text-gray-500">
                                        {formatDuration(r.started_at, r.finished_at)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <Pagination paginator={runs} />
        </AdminLayout>
    );
}
