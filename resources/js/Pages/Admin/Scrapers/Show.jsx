import { Head, Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Button } from '@/Components/ui/Button';
import { Select } from '@/Components/ui/Select';
import { Badge } from '@/Components/ui/Badge';
import Pagination from '@/Components/Pagination';
import DailyBarChart from '@/Components/Admin/DailyBarChart';
import RunStatusBadge from '@/Components/Admin/RunStatusBadge';
import StatCard from '@/Components/Admin/StatCard';
import { formatDuration, formatRunTime } from '@/lib/dates';

/**
 * Render whichever shape `error_log` holds. The orchestrator writes plain
 * strings; older rows and factory data write `{message, timestamp}` objects.
 *
 * @param {unknown} entry
 * @returns {string}
 */
function errorText(entry) {
    const text = typeof entry === 'string' ? entry : entry?.message;

    if (typeof text === 'string' && text.trim() !== '') {
        return text;
    }

    // Never render "null", "{}" or a blank bullet as though it were a
    // diagnosis — an unreadable error still has to read as an error.
    return 'Unreadable error entry (' + (entry === null ? 'null' : typeof entry) + ')';
}

/**
 * @param {number|null} seconds
 * @returns {string}
 */
function humanSeconds(seconds) {
    if (seconds === null || seconds === undefined) {
        return '—';
    }

    return seconds < 60 ? `${seconds}s` : `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
}

/**
 * Health and daily stats for one configured scraper in one city.
 *
 * @param {Object} props
 * @param {Object} props.source Adapter/city identity plus its config entry.
 * @param {{days: Array<Object>, totals: Object, health: Object}} props.stats
 * @param {number} props.range Days covered by the current window.
 * @param {Array<number>} props.ranges Windows on offer.
 * @param {{data: Array<Object>}} props.runs Recent runs for this source.
 */
export default function ScraperShow({ source, stats, range, ranges = [], runs }) {
    const { days, totals, health } = stats;
    const rows = runs.data || [];

    const unhealthy = health.consecutive_failures >= health.failure_threshold;

    const changeRange = (value) => {
        router.get(
            `/admin/scrapers/${source.city}/${source.adapter}`,
            { range: value },
            { preserveState: true, preserveScroll: true, replace: true }
        );
    };

    const queueRun = () => {
        router.post(
            '/admin/scrapers/run',
            { city: source.city, source: source.adapter },
            { preserveScroll: true }
        );
    };

    return (
        <AdminLayout title={`${source.adapter} — ${source.city_label}`}>
            <Head title={`Admin — ${source.adapter}`} />

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <Link href="/admin/scrapers" className="text-sm text-[#FF5733] hover:underline">
                    ← Back to scrapers
                </Link>
                <div className="flex items-center gap-2">
                    <Select
                        value={range}
                        onChange={(e) => changeRange(Number(e.target.value))}
                        aria-label="Stats range"
                        className="w-auto"
                    >
                        {ranges.map((r) => (
                            <option key={r} value={r}>Last {r} days</option>
                        ))}
                    </Select>
                    <Button type="button" onClick={queueRun}>Queue run</Button>
                </div>
            </div>

            {unhealthy && (
                <div className="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                    {health.consecutive_failures} consecutive failures — at or above the alert
                    threshold of {health.failure_threshold}. This source is likely broken.
                </div>
            )}

            <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4">
                <StatCard
                    label="Events found"
                    value={totals.found.toLocaleString()}
                    hint={`over ${range} days`}
                />
                <StatCard
                    label="New events"
                    value={totals.created.toLocaleString()}
                    hint={`${totals.updated.toLocaleString()} updated`}
                />
                <StatCard
                    label="Success rate"
                    value={health.success_rate === null ? '—' : `${Math.round(health.success_rate * 100)}%`}
                    hint={`${totals.completed} ok · ${totals.failed} failed${totals.running > 0 ? ` · ${totals.running} open` : ''}`}
                />
                <StatCard
                    label="In database"
                    value={health.events_total.toLocaleString()}
                    hint={`${health.events_upcoming.toLocaleString()} upcoming`}
                />
            </div>

            <div className="mb-6 grid gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader><CardTitle className="text-lg">Events found per day</CardTitle></CardHeader>
                    <CardContent>
                        <DailyBarChart data={days.map((d) => ({ day: d.day, value: d.found, failed: d.failed }))} label="Events found per day" />
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader><CardTitle className="text-lg">New events per day</CardTitle></CardHeader>
                    <CardContent>
                        <DailyBarChart
                            data={days.map((d) => ({ day: d.day, value: d.created, failed: d.failed }))}
                            label="New events per day"
                            color="#FF5733"
                        />
                    </CardContent>
                </Card>
            </div>

            <div className="mb-6 grid gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader><CardTitle className="text-lg">Configuration</CardTitle></CardHeader>
                    <CardContent className="space-y-2 text-sm">
                        <p>
                            <span className="text-gray-500">Status: </span>
                            <Badge variant={source.enabled ? 'default' : 'outline'}>
                                {source.enabled ? 'enabled' : 'disabled'}
                            </Badge>
                        </p>
                        <p>
                            <span className="text-gray-500">Interval: </span>
                            {source.interval_hours ? `every ${source.interval_hours}h` : '—'}
                        </p>
                        {source.url && (
                            <p className="break-all">
                                <span className="text-gray-500">URL: </span>
                                <a href={source.url} target="_blank" rel="noreferrer" className="text-[#FF5733] hover:underline">
                                    {source.url}
                                </a>
                            </p>
                        )}
                        {(source.extra_urls || []).map((url) => (
                            <p key={url} className="break-all">
                                <span className="text-gray-500">Also: </span>
                                <a href={url} target="_blank" rel="noreferrer" className="text-[#FF5733] hover:underline">
                                    {url}
                                </a>
                            </p>
                        ))}
                        {source.city_filter && (
                            <p><span className="text-gray-500">City filter: </span>{source.city_filter}</p>
                        )}
                        {Object.keys(source.params || {}).length > 0 && (
                            <div>
                                <span className="text-gray-500">Params:</span>
                                <pre className="mt-1 max-h-40 overflow-auto rounded bg-gray-50 p-2 text-xs">
                                    {JSON.stringify(source.params, null, 2)}
                                </pre>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle className="text-lg">Health</CardTitle></CardHeader>
                    <CardContent className="space-y-2 text-sm">
                        <p>
                            <span className="text-gray-500">Last run: </span>
                            {health.last_run ? (
                                <>
                                    <RunStatusBadge status={health.last_run.status} />
                                    <span className="ml-2 text-gray-500">
                                        {formatRunTime(health.last_run.started_at)}
                                    </span>
                                </>
                            ) : <span className="text-gray-400">never</span>}
                        </p>
                        <p>
                            <span className="text-gray-500">Last success: </span>
                            {health.last_success
                                ? formatRunTime(health.last_success.started_at)
                                : <span className="text-gray-400">never</span>}
                        </p>
                        <p>
                            <span className="text-gray-500">Consecutive failures: </span>
                            {health.consecutive_failures}
                            <span className="text-gray-400"> / {health.failure_threshold} to alert</span>
                        </p>
                        <p>
                            <span className="text-gray-500">Average duration: </span>
                            {humanSeconds(health.avg_duration_seconds)}
                        </p>
                        <p className="pt-2 text-xs text-gray-500">
                            A run that fails on its own keeps whatever it scraped first; one
                            killed outright by its worker keeps nothing, so its counters read
                            zero. Retries of one queued job share a run, so &quot;Runs&quot;
                            counts dispatches rather than attempts.
                        </p>
                    </CardContent>
                </Card>
            </div>

            <Card className="mb-6">
                <CardHeader><CardTitle className="text-lg">Daily breakdown</CardTitle></CardHeader>
                <CardContent className="p-0 overflow-x-auto">
                    <table className="w-full min-w-[640px] text-sm">
                        <thead className="bg-gray-50 text-left text-gray-500">
                            <tr>
                                <th className="px-4 py-2">Day</th>
                                <th className="px-4 py-2">Runs</th>
                                <th className="px-4 py-2">Failed</th>
                                <th className="px-4 py-2">Open</th>
                                <th className="px-4 py-2">Found</th>
                                <th className="px-4 py-2">New</th>
                                <th className="px-4 py-2">Updated</th>
                                <th className="px-4 py-2">Skipped</th>
                            </tr>
                        </thead>
                        <tbody>
                            {[...days].reverse().map((d) => (
                                <tr
                                    key={d.day}
                                    className={`border-t border-gray-100 ${d.runs === 0 ? 'text-gray-400' : ''}`}
                                >
                                    <td className="px-4 py-2">{d.day}</td>
                                    <td className="px-4 py-2">{d.runs}</td>
                                    <td className={`px-4 py-2 ${d.failed > 0 ? 'font-medium text-red-600' : ''}`}>
                                        {d.failed}
                                    </td>
                                    <td className={`px-4 py-2 ${d.running > 0 ? 'font-medium text-amber-600' : ''}`}>
                                        {d.running}
                                    </td>
                                    <td className="px-4 py-2">{d.found}</td>
                                    <td className="px-4 py-2">{d.created}</td>
                                    <td className="px-4 py-2">{d.updated}</td>
                                    <td className="px-4 py-2">{d.skipped}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <Card>
                <CardHeader><CardTitle className="text-lg">Recent runs</CardTitle></CardHeader>
                <CardContent className="p-0 overflow-x-auto">
                    <table className="w-full min-w-[640px] text-sm">
                        <thead className="bg-gray-50 text-left text-gray-500">
                            <tr>
                                <th className="px-4 py-2">Started</th>
                                <th className="px-4 py-2">Status</th>
                                <th className="px-4 py-2">Duration</th>
                                <th className="px-4 py-2">Found</th>
                                <th className="px-4 py-2">New</th>
                                <th className="px-4 py-2">Errors</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 && (
                                <tr><td colSpan={6} className="px-4 py-6 text-center text-gray-400">No runs yet.</td></tr>
                            )}
                            {rows.map((r) => (
                                <tr key={r.id} className="border-t border-gray-100 align-top">
                                    <td className="px-4 py-2 text-gray-500">{formatRunTime(r.started_at)}</td>
                                    <td className="px-4 py-2"><RunStatusBadge status={r.status} /></td>
                                    <td className="px-4 py-2 text-gray-500">
                                        {formatDuration(r.started_at, r.finished_at)}
                                    </td>
                                    <td className="px-4 py-2">{r.events_found ?? 0}</td>
                                    <td className="px-4 py-2">{r.events_created ?? 0}</td>
                                    <td className="px-4 py-2">
                                        {(r.error_log || []).length === 0 ? (
                                            r.errors_count || 0
                                        ) : (
                                            <ul className="space-y-1 text-xs text-red-600">
                                                {r.error_log.map((entry, i) => (
                                                    <li key={i}>{errorText(entry)}</li>
                                                ))}
                                            </ul>
                                        )}
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
