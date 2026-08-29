import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Card, CardHeader, CardTitle, CardContent } from '@/Components/ui/Card';
import { Button } from '@/Components/ui/Button';

/**
 * Activity types worth a headline tile, in reading order. The rest are still in
 * `counts` and shown in the breakdown table below.
 */
const HEADLINE_TYPES = [
    { key: 'event_impression', label: 'Afișări' },
    { key: 'event_view', label: 'Vizualizări' },
    { key: 'event_click', label: 'Clicuri' },
    { key: 'calendar_download', label: 'În calendar' },
    { key: 'search', label: 'Căutări' },
];

const TYPE_LABELS = {
    event_impression: 'Afișare card',
    event_view: 'Vizualizare pagină',
    event_click: 'Click către sursă',
    calendar_download: 'Adăugat în calendar',
    reaction_interested: 'Mă interesează',
    reaction_not_interested: 'Nu-i pentru mine',
    reaction_cleared: 'Reacție retrasă',
    bookmark_added: 'Salvare',
    bookmark_removed: 'Salvare retrasă',
    search: 'Căutare / filtrare',
    email_open: 'Email deschis',
    email_click: 'Click în email',
};

/**
 * @param {Object} props
 * @param {string} props.label
 * @param {string|number} props.value
 * @param {string} [props.hint]
 */
function Stat({ label, value, hint }) {
    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium text-gray-500">
                    {label}
                </CardTitle>
            </CardHeader>
            <CardContent>
                <p className="text-3xl font-bold text-[#0A1128]">{value}</p>
                {hint && <p className="text-xs text-gray-400 mt-1">{hint}</p>}
            </CardContent>
        </Card>
    );
}

/**
 * @param {number} rate A fraction in [0, 1].
 */
function percent(rate) {
    return `${(rate * 100).toFixed(1)}%`;
}

/**
 * @param {Object} props
 * @param {Object} props.summary
 * @param {Array<number>} props.windows
 */
export default function Analytics({ summary, windows }) {
    const { counts, click_through_rate: ctr, digest, top_events: topEvents, top_searches: topSearches } = summary;

    return (
        <AdminLayout title="Analytics">
            <Head title="Admin — Analytics" />

            <div className="flex items-center gap-2 mb-6">
                <span className="text-sm text-gray-500">Interval:</span>
                {windows.map((days) => (
                    <Button
                        key={days}
                        size="sm"
                        variant={summary.window_days === days ? 'default' : 'outline'}
                        onClick={() =>
                            router.visit(`/admin/analytics?window=${days}`, {
                                preserveScroll: true,
                            })
                        }
                    >
                        {days} zile
                    </Button>
                ))}
            </div>

            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                {HEADLINE_TYPES.map(({ key, label }) => (
                    <Stat key={key} label={label} value={counts[key] ?? 0} />
                ))}
                <Stat
                    label="Rată de click"
                    value={percent(ctr)}
                    hint="Clicuri ÷ afișări, fără trafic automat"
                />
                <Stat
                    label="Digesturi trimise"
                    value={digest.sent}
                    hint="Doar canalele cu email"
                />
                <Stat
                    label="Rată de deschidere"
                    value={percent(digest.open_rate)}
                    hint={`${digest.opened} deschise`}
                />
                <Stat label="Clicuri din email" value={digest.clicks} />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Evenimente cu cele mai multe clicuri</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {topEvents.length === 0 ? (
                            <p className="text-sm text-gray-400">
                                Niciun click înregistrat în acest interval.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="text-left text-gray-500 border-b">
                                            <th className="py-2 font-medium">Eveniment</th>
                                            <th className="py-2 font-medium text-right">Clicuri</th>
                                            <th className="py-2 font-medium text-right">Afișări</th>
                                            <th className="py-2 font-medium text-right">CTR</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {topEvents.map((event) => (
                                            <tr key={event.id} className="border-b last:border-0">
                                                <td className="py-2 pr-4">{event.title}</td>
                                                <td className="py-2 text-right tabular-nums">
                                                    {event.clicks}
                                                </td>
                                                <td className="py-2 text-right tabular-nums text-gray-500">
                                                    {event.impressions}
                                                </td>
                                                <td className="py-2 text-right tabular-nums text-gray-500">
                                                    {event.impressions > 0
                                                        ? percent(event.clicks / event.impressions)
                                                        : '—'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Cele mai căutate</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {topSearches.length === 0 ? (
                            <p className="text-sm text-gray-400">
                                Nicio căutare în acest interval.
                            </p>
                        ) : (
                            <ul className="text-sm divide-y">
                                {topSearches.map(({ term, hits }) => (
                                    <li
                                        key={term}
                                        className="py-2 flex items-center justify-between gap-4"
                                    >
                                        <span className="truncate">{term}</span>
                                        <span className="tabular-nums text-gray-500">{hits}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>

            <Card className="mt-6">
                <CardHeader>
                    <CardTitle>Toate semnalele</CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <tbody>
                                {Object.entries(counts).map(([type, hits]) => (
                                    <tr key={type} className="border-b last:border-0">
                                        <td className="py-2">{TYPE_LABELS[type] ?? type}</td>
                                        <td className="py-2 text-right tabular-nums">{hits}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>
        </AdminLayout>
    );
}
