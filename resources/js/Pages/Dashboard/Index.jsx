import { Head, Link, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import EventList from '@/Components/Events/EventList';
import StatTile from '@/Components/StatTile';
import { CATEGORIES } from '@/lib/categories';

/**
 * A nudge banner in the app's accent colour, used for the empty states that
 * have something actionable behind them.
 *
 * @param {Object} props
 * @param {string} props.message
 * @param {string} props.href
 * @param {string} props.cta
 */
function CalloutBanner({ message, href, cta }) {
    return (
        <div className="mb-6 flex flex-wrap items-center justify-between gap-4 rounded-lg border border-[#FF5733]/20 bg-[#FF5733]/5 px-5 py-4">
            <p className="text-sm text-gray-700">{message}</p>
            <Link
                href={href}
                className="inline-flex min-h-11 items-center whitespace-nowrap rounded-full bg-[#FF5733] px-5 py-2.5 text-sm font-semibold text-white transition-opacity hover:opacity-90 sm:min-h-0"
            >
                {cta}
            </Link>
        </div>
    );
}

/**
 * @param {Object} props
 * @param {string} props.title
 * @param {string} [props.subtitle]
 * @param {React.ReactNode} [props.action]
 */
function SectionHeading({ title, subtitle, action }) {
    return (
        <div className="mb-4 flex items-end justify-between gap-4">
            <div>
                <h2 className="text-xl font-semibold text-gray-900">{title}</h2>
                {subtitle && <p className="mt-1 text-sm text-gray-500">{subtitle}</p>}
            </div>
            {action}
        </div>
    );
}

/**
 * The "Acasă" dashboard.
 *
 * @param {Object} props
 * @param {Array<Object>} props.recommendations
 * @param {Array<Object>} props.discoveryEvents
 * @param {Array<Object>} props.weekendEvents
 * @param {{upcoming: number, saved: number, interested: number, profile_completeness: number}} props.stats
 * @param {string} props.city
 * @param {boolean} props.onboardingCompleted
 * @param {boolean} props.hasCity
 * @param {boolean} props.hasEventsInCity
 */
export default function Index({
    recommendations = [],
    discoveryEvents = [],
    weekendEvents = [],
    stats = { upcoming: 0, saved: 0, interested: 0, profile_completeness: 0 },
    city = '',
    onboardingCompleted = true,
    hasCity = true,
    hasEventsInCity = true,
}) {
    const { auth } = usePage().props;
    const firstName = auth?.user?.name?.split(' ')[0] ?? '';

    // Ordered by what blocks the user most: no profile beats no city, and both
    // beat an empty catalogue. Only the first applicable one is shown, so the
    // page never stacks three competing calls to action.
    const callout = !onboardingCompleted
        ? {
              message:
                  'Nu ți-ai făcut încă profilul de interese. Câteva minute de chat și lista de mai jos se rescrie după gusturile tale.',
              href: '/onboarding',
              cta: 'Începe',
          }
        : !hasCity
          ? {
                message:
                    'Nu ai setat orașul, așa că nu putem filtra evenimentele din apropiere. Alege-l în profil.',
                href: '/profile',
                cta: 'Setează orașul',
            }
          : !hasEventsInCity
            ? {
                  message: `Nu avem încă evenimente viitoare în ${city}. Între timp poți răsfoi tot ce am adunat.`,
                  href: '/events',
                  cta: 'Vezi toate evenimentele',
              }
            : null;

    return (
        <AppLayout title={firstName ? `Bună, ${firstName}` : 'Acasă'}>
            <Head title="Acasă" />

            <p className="-mt-4 mb-6 text-sm text-gray-500">
                Ce se întâmplă în {city} în perioada următoare.
            </p>

            {callout && <CalloutBanner {...callout} />}

            {/* Stats */}
            <section className="mb-10 grid grid-cols-2 gap-4 md:grid-cols-4">
                <StatTile label="Evenimente viitoare" value={stats.upcoming} hint={city} />
                <StatTile label="Salvate" value={stats.saved} />
                <StatTile label="Îți plac" value={stats.interested} />
                <StatTile
                    label="Profil completat"
                    value={`${stats.profile_completeness}%`}
                    hint={stats.profile_completeness < 100 ? 'Reacționează ca să-l rafinezi' : undefined}
                />
            </section>

            {/* Weekend */}
            {weekendEvents.length > 0 && (
                <section className="mb-10">
                    <SectionHeading
                        title="În weekend"
                        subtitle="Ce se întâmplă sâmbătă și duminică"
                        action={
                            <Link
                                href="/events?range=weekend"
                                className="whitespace-nowrap text-sm font-medium text-[#FF5733] hover:underline"
                            >
                                Vezi tot
                            </Link>
                        }
                    />
                    <EventList events={weekendEvents} />
                </section>
            )}

            {/* Recommended */}
            <section className="mb-10">
                <SectionHeading
                    title="Recomandate pentru tine"
                    subtitle="Alese după profilul tău de interese"
                />
                <EventList
                    events={recommendations}
                    emptyMessage={
                        onboardingCompleted
                            ? 'Nicio recomandare momentan. Reacționează la câteva evenimente ca să învățăm ce-ți place.'
                            : 'Finalizează onboarding-ul pentru sugestii personalizate.'
                    }
                />
            </section>

            {/* Discovery */}
            <section className="mb-10">
                <SectionHeading
                    title="Descoperă ceva nou"
                    subtitle="Evenimente în afara intereselor tale obișnuite care ți-ar putea plăcea"
                />
                <EventList
                    events={discoveryEvents}
                    emptyMessage="Niciun eveniment de descoperit momentan."
                />
            </section>

            {/* Category shortcuts */}
            <section>
                <SectionHeading title="Caută după categorie" />
                <div className="flex flex-wrap gap-2">
                    {CATEGORIES.map(({ value, label }) => (
                        <Link
                            key={value}
                            href={`/events?category=${value}`}
                            className="inline-flex min-h-11 items-center rounded-full bg-gray-100 px-4 py-1.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-200 sm:min-h-0"
                        >
                            {label}
                        </Link>
                    ))}
                </div>
            </section>
        </AppLayout>
    );
}
