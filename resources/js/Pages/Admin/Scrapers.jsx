import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Button } from '@/Components/ui/Button';
import { Select } from '@/Components/ui/Select';
import { Badge } from '@/Components/ui/Badge';

/**
 * @param {Object} props
 * @param {{data: Array<Object>}} props.runs
 * @param {Array<string>} props.cities
 * @param {Object<string, Array<{adapter: string, enabled: boolean}>>} props.adapters
 *   Sources configured per city — keyed by city so we never offer a source that
 *   city has not configured.
 */
export default function Scrapers({ runs, cities = [], adapters = {} }) {
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
                    <form onSubmit={run} className="flex flex-wrap gap-2 items-end">
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
                </CardContent>
            </Card>

            <Card>
                <CardContent className="p-0 overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="bg-gray-50 text-left text-gray-500">
                            <tr>
                                <th className="px-4 py-2">Source</th>
                                <th className="px-4 py-2">City</th>
                                <th className="px-4 py-2">Status</th>
                                <th className="px-4 py-2">New</th>
                                <th className="px-4 py-2">Started</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 && (
                                <tr><td colSpan={5} className="px-4 py-6 text-center text-gray-400">No runs yet.</td></tr>
                            )}
                            {rows.map((r) => (
                                <tr key={r.id} className="border-t border-gray-100">
                                    <td className="px-4 py-2">{r.source_name ?? r.source}</td>
                                    <td className="px-4 py-2">{r.city}</td>
                                    <td className="px-4 py-2"><Badge>{r.status}</Badge></td>
                                    <td className="px-4 py-2">{r.events_new ?? 0}</td>
                                    <td className="px-4 py-2">{r.started_at}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </CardContent>
            </Card>
        </AdminLayout>
    );
}
