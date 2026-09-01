import { Link } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import CategoryBadge from '@/Components/Events/CategoryBadge';
import { formatDayMonth } from '@/lib/dates';

const REACTION_MARKS = {
    interested: { symbol: '♥', className: 'text-[#FF5733]', label: 'Mă interesează' },
    not_interested: { symbol: '✕', className: 'text-gray-400', label: 'Nu-i pentru mine' },
};

/* Never fall back to one of the real reactions: a third Reaction case would
   otherwise render every row of that type as ✕ and have screen readers announce
   "Nu-i pentru mine" for an event the user never rejected. */
const UNKNOWN_REACTION = { symbol: '•', className: 'text-gray-300', label: 'Reacție necunoscută' };

/**
 * @param {Object} props
 * @param {string} props.label
 * @param {number} props.value
 */
function Figure({ label, value }) {
    return (
        <div>
            <p className="text-2xl font-bold text-[#0A1128]">{value}</p>
            <p className="text-xs text-gray-500">{label}</p>
        </div>
    );
}

/**
 * The user's own activity, summarised. Everything here is derived on read from
 * `ProfileActivitySummarizer` — see that class for why the implicit counts
 * carry a window and the saved count does not match the /saved page.
 *
 * @param {Object} props
 * @param {Object} props.activity
 * @param {{interested: number, not_interested: number}} props.activity.reactions
 * @param {number} props.activity.saved
 * @param {Array<{category: string, count: number}>} props.activity.top_categories
 * @param {Array<{event_id: string, event_title: ?string, reaction: string, created_at: ?string}>} props.activity.recent
 * @param {{event_view: number, event_click: number, calendar_download: number, search: number}} props.activity.implicit
 * @param {number} props.activity.implicit_window_days
 * @param {boolean} props.activity.has_activity
 */
export default function ActivitySummaryCard({ activity }) {
    /* Say so rather than vanishing. A card that silently disappears is
       indistinguishable from a section that was never built, which is how the
       empty interests card went unnoticed for as long as it did. */
    if (!activity) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle className="text-lg">Activitatea ta</CardTitle>
                </CardHeader>
                <CardContent>
                    <p className="text-sm text-gray-400">
                        Nu am putut încărca activitatea ta acum. Încearcă să reîncarci pagina.
                    </p>
                </CardContent>
            </Card>
        );
    }

    const {
        reactions,
        saved,
        top_categories: topCategories,
        recent,
        implicit,
        implicit_window_days: windowDays,
        has_activity: hasActivity,
    } = activity;

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-lg">Activitatea ta</CardTitle>
            </CardHeader>
            <CardContent className="space-y-6">
                {!hasActivity ? (
                    <p className="text-sm text-gray-400">
                        Încă nu ai reacționat la niciun eveniment.{' '}
                        <Link
                            href="/events"
                            className="font-medium text-[#FF5733] hover:underline"
                        >
                            Explorează evenimentele
                        </Link>{' '}
                        — fiecare reacție ne ajută să-ți recomandăm mai bine.
                    </p>
                ) : (
                    <>
                        <div className="grid grid-cols-3 gap-4">
                            <Figure label="Îți plac" value={reactions.interested} />
                            <Figure label="Nu-s pentru tine" value={reactions.not_interested} />
                            <Figure label="Salvate" value={saved} />
                        </div>

                        {topCategories.length > 0 && (
                            <div className="space-y-2">
                                <p className="text-sm font-medium text-gray-700">
                                    Categoriile care îți plac cel mai des
                                </p>
                                <div className="flex flex-wrap items-center gap-2">
                                    {topCategories.map(({ category, count }) => (
                                        <span
                                            key={category}
                                            className="inline-flex items-center gap-1.5"
                                        >
                                            <CategoryBadge category={category} />
                                            <span className="text-xs text-gray-500">
                                                ×{count}
                                            </span>
                                        </span>
                                    ))}
                                </div>
                            </div>
                        )}

                        {recent.length > 0 && (
                            <div className="space-y-2">
                                <p className="text-sm font-medium text-gray-700">
                                    Ultimele reacții
                                </p>
                                <ul className="divide-y divide-gray-100">
                                    {recent.map((item) => {
                                        const mark =
                                            REACTION_MARKS[item.reaction] ?? UNKNOWN_REACTION;

                                        return (
                                            <li
                                                key={`${item.event_id}-${item.created_at}`}
                                                className="flex items-center gap-3 py-2"
                                            >
                                                <span
                                                    aria-label={mark.label}
                                                    title={mark.label}
                                                    className={mark.className}
                                                >
                                                    {mark.symbol}
                                                </span>
                                                <Link
                                                    href={`/events/${item.event_id}`}
                                                    className="flex-1 truncate text-sm text-gray-700 hover:underline"
                                                >
                                                    {item.event_title ?? 'Eveniment indisponibil'}
                                                </Link>
                                                <span className="shrink-0 text-xs text-gray-400">
                                                    {formatDayMonth(item.created_at)}
                                                </span>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </div>
                        )}

                        <div className="border-t border-gray-100 pt-4">
                            <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                                <Figure label="Evenimente văzute" value={implicit.event_view} />
                                <Figure label="Deschise" value={implicit.event_click} />
                                <Figure label="În calendar" value={implicit.calendar_download} />
                                <Figure label="Căutări" value={implicit.search} />
                            </div>
                            <p className="mt-2 text-xs text-gray-400">
                                Ultimele {windowDays} de zile — păstrăm istoricul de
                                navigare doar atât.
                            </p>
                        </div>
                    </>
                )}
            </CardContent>
        </Card>
    );
}
