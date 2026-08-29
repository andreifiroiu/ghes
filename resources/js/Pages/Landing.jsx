import { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { Menu, X } from 'lucide-react';
import { CATEGORIES } from '@/lib/categories';

/**
 * Hatched fill standing in for artwork we don't have: event posters that failed
 * to scrape, and the app screenshots that aren't taken yet.
 */
const HATCH = 'repeating-linear-gradient(135deg,#e9ebf0 0 6px,#f2f3f7 6px 12px)';

const CATEGORY_LABELS = Object.fromEntries(
    CATEGORIES.map(({ value, label }) => [value, label]),
);

const numberFormatter = new Intl.NumberFormat('ro-RO');

const SECTION_LINKS = [
    { href: '#evenimente', label: 'Evenimente' },
    { href: '#cum-functioneaza', label: 'Cum funcționează' },
    { href: '#intrebari', label: 'Întrebări' },
    { href: '#organizatori', label: 'Organizatori' },
];

/** @param {string} value */
const capitalise = (value) => value.charAt(0).toUpperCase() + value.slice(1);

/** @param {string} isoDate */
const isoDay = (isoDate) => isoDate.slice(0, 10);

const dayOffset = (days) => {
    const date = new Date();
    date.setDate(date.getDate() + days);
    return date.toISOString();
};

/**
 * "Vineri, 20:00" — weekday and start time, the way the design writes it.
 *
 * @param {string|null|undefined} startsAt
 */
function formatWhen(startsAt) {
    if (!startsAt) {
        return null;
    }

    const date = new Date(startsAt);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    const weekday = capitalise(date.toLocaleDateString('ro-RO', { weekday: 'long' }));
    const time = date.toLocaleTimeString('ro-RO', { hour: '2-digit', minute: '2-digit' });

    return `${weekday}, ${time}`;
}

/**
 * @param {{ is_free?: boolean, price_min?: number|null }} event
 */
function formatPrice(event) {
    if (event.is_free) {
        return 'gratuit';
    }

    if (event.price_min != null) {
        return `de la ${numberFormatter.format(event.price_min)} lei`;
    }

    return null;
}

/** @param {number|null|undefined} count */
function formatSources(count) {
    const total = count ?? 1;

    return `${total} ${total === 1 ? 'sursă' : 'surse'}`;
}

/**
 * "actualizat acum 6 minute" — the design's freshness line, driven by the last
 * finished scraper run. Returns null when nothing has ever run, so the badge can
 * fall back rather than claim a time it doesn't have.
 *
 * @param {string|null|undefined} lastScrapedAt
 */
function formatFreshness(lastScrapedAt) {
    if (!lastScrapedAt) {
        return null;
    }

    const then = new Date(lastScrapedAt);

    if (Number.isNaN(then.getTime())) {
        return null;
    }

    const minutes = Math.max(0, Math.round((Date.now() - then.getTime()) / 60000));

    if (minutes < 1) {
        return 'actualizat chiar acum';
    }

    if (minutes < 60) {
        return `actualizat acum ${minutes} ${minutes === 1 ? 'minut' : 'minute'}`;
    }

    const hours = Math.round(minutes / 60);

    if (hours < 24) {
        return `actualizat acum ${hours} ${hours === 1 ? 'oră' : 'ore'}`;
    }

    const days = Math.round(hours / 24);

    return `actualizat acum ${days} ${days === 1 ? 'zi' : 'zile'}`;
}

/** @param {{ children: React.ReactNode, className?: string }} props */
function Container({ children, className = '' }) {
    return (
        <div className={`mx-auto w-full max-w-[1200px] px-5 sm:px-8 ${className}`}>
            {children}
        </div>
    );
}

/** @param {{ children: React.ReactNode }} props */
function Eyebrow({ children }) {
    return (
        <div className="text-[11px] font-bold uppercase tracking-[0.16em] text-persimmon">
            {children}
        </div>
    );
}

/** @param {{ label: string, className?: string }} props */
function Placeholder({ label, className = '' }) {
    return (
        <div
            className={`flex items-center justify-center ${className}`}
            style={{ backgroundImage: HATCH }}
        >
            <span className="px-3 text-center font-mono text-[11px] tracking-[0.08em] text-[#9aa1af]">
                {label}
            </span>
        </div>
    );
}

/**
 * One event in the public preview grid. Deliberately not the app's
 * `Components/Events/EventCard` — that one carries reaction buttons and a
 * light-UI card shell, neither of which belongs on a guest marketing page.
 *
 * @param {{ event: Object }} props
 */
function PreviewCard({ event }) {
    const when = formatWhen(event.starts_at);
    const price = formatPrice(event);
    const category = CATEGORY_LABELS[event.category] ?? null;

    return (
        <Link
            href={`/events/${event.id}`}
            className="flex flex-col overflow-hidden rounded-[14px] border border-navy/8 bg-white text-navy transition-colors hover:border-navy/20"
        >
            {event.image_url ? (
                <img
                    src={event.image_url}
                    alt=""
                    loading="lazy"
                    className="aspect-[16/10] w-full object-cover"
                />
            ) : (
                <Placeholder label="afiș eveniment" className="aspect-[16/10] w-full" />
            )}
            <div className="flex flex-1 flex-col gap-2.5 px-5 pb-[18px] pt-5">
                {category && (
                    <div className="text-[10px] font-bold uppercase tracking-[0.14em] text-persimmon">
                        {category}
                    </div>
                )}
                <div className="font-display text-lg font-bold leading-[1.3]">
                    {event.title}
                </div>
                <div className="text-sm text-navy/55">
                    {[when, event.venue].filter(Boolean).join(' · ')}
                </div>
                <div className="mt-auto flex justify-between border-t border-navy/[0.07] pt-3.5 text-xs text-navy/45">
                    <span>{formatSources(event.sources_count)}</span>
                    {price && <span>{price}</span>}
                </div>
            </div>
        </Link>
    );
}

/** @param {{ value: string, label: string, accent?: boolean }} props */
function Stat({ value, label, accent = false }) {
    return (
        <div className="border-r border-ghost/10 py-7 pl-4 first:pl-0 [&:nth-child(-n+2)]:border-b [&:nth-child(2n)]:border-r-0 md:pl-7 md:first:pl-0 md:[&:nth-child(-n+2)]:border-b-0 md:[&:nth-child(2)]:border-r">
            <div
                className={`font-display text-[28px] font-extrabold tracking-[-0.02em] sm:text-[34px] ${
                    accent ? 'text-persimmon' : ''
                }`}
            >
                {value}
            </div>
            <div className="mt-2 text-xs font-semibold uppercase tracking-[0.1em] text-ghost/40">
                {label}
            </div>
        </div>
    );
}

/**
 * @param {Object} props
 * @param {Array<Object>} [props.events] - Upcoming events for the public preview grid
 * @param {Object} [props.stats]
 * @param {number} props.stats.active
 * @param {number} props.stats.sources
 * @param {number} props.stats.added_today
 * @param {string|null} props.stats.last_scraped_at
 * @param {string} [props.city]
 */
export default function Landing({ events = [], stats = {}, city = 'Timișoara' }) {
    const [menuOpen, setMenuOpen] = useState(false);
    const freshness = formatFreshness(stats.last_scraped_at);
    const hasEvents = events.length > 0;

    const datePills = [
        { label: 'Azi', href: `/events?date=${isoDay(dayOffset(0))}` },
        { label: 'Mâine', href: `/events?date=${isoDay(dayOffset(1))}` },
        { label: 'Weekend', href: '/events?range=weekend' },
        { label: 'Toate datele', href: '/events' },
    ];

    return (
        <>
            <Head title="Ghes — Orașul îți dă ghes. Tu ce faci diseară?" />

            <div className="landing-root font-sans antialiased">
                {/* ── NAV ──────────────────────────────────────────────── */}
                <nav className="mx-auto flex w-full max-w-[1200px] items-center justify-between gap-6 px-5 py-5 sm:px-8">
                    <Link href="/" className="flex items-center gap-3 hover:text-persimmon">
                        <img
                            src="/images/logo-dark.png"
                            alt="Ghes"
                            className="block h-9 w-9 rounded-lg"
                        />
                        <span className="font-display text-[19px] font-extrabold tracking-[-0.01em]">
                            ghes
                        </span>
                    </Link>

                    <div className="hidden items-center gap-7 text-sm font-medium text-ghost/60 lg:flex">
                        {SECTION_LINKS.map(({ href, label }) => (
                            <a key={href} href={href} className="py-1 hover:text-persimmon">
                                {label}
                            </a>
                        ))}
                    </div>

                    <div className="flex items-center gap-2">
                        <Link
                            href="/login"
                            className="hidden px-3.5 py-2.5 text-sm font-medium text-ghost/70 hover:text-persimmon sm:block"
                        >
                            Intră în cont
                        </Link>
                        <Link
                            href="/register"
                            className="inline-flex min-h-11 items-center rounded-full bg-persimmon px-5 py-2.5 text-sm font-semibold text-white transition-opacity hover:opacity-90 sm:min-h-0"
                        >
                            Înregistrează-te
                        </Link>
                        <button
                            type="button"
                            aria-label={menuOpen ? 'Închide meniul' : 'Deschide meniul'}
                            aria-expanded={menuOpen}
                            onClick={() => setMenuOpen(!menuOpen)}
                            className="-mr-2 inline-flex h-11 w-11 items-center justify-center rounded-md text-ghost hover:text-persimmon lg:hidden"
                        >
                            {menuOpen ? (
                                <X className="h-6 w-6" aria-hidden="true" />
                            ) : (
                                <Menu className="h-6 w-6" aria-hidden="true" />
                            )}
                        </button>
                    </div>
                </nav>

                {/*
                  Mobile drawer. Without it the four section anchors are
                  unreachable on a phone, and "Intră în cont" — hidden below
                  `sm` in the bar above — has no home until the footer.
                */}
                {menuOpen && (
                    <div className="border-y border-ghost/10 lg:hidden">
                        <div className="mx-auto w-full max-w-[1200px] px-5 py-2 sm:px-8">
                            {SECTION_LINKS.map(({ href, label }) => (
                                <a
                                    key={href}
                                    href={href}
                                    onClick={() => setMenuOpen(false)}
                                    className="flex min-h-12 items-center text-base font-medium text-ghost/70 hover:text-persimmon"
                                >
                                    {label}
                                </a>
                            ))}
                            <Link
                                href="/login"
                                onClick={() => setMenuOpen(false)}
                                className="flex min-h-12 items-center border-t border-ghost/10 text-base font-semibold text-ghost hover:text-persimmon"
                            >
                                Intră în cont
                            </Link>
                        </div>
                    </div>
                )}

                {/* ── HERO ─────────────────────────────────────────────── */}
                <header className="mx-auto w-full max-w-[1200px] px-5 pt-16 text-center sm:px-8 sm:pt-[88px]">
                    <div className="inline-flex items-center gap-2.5 rounded-full border border-ghost/15 py-[7px] pl-3 pr-3.5 text-[11px] font-semibold uppercase tracking-[0.14em] text-ghost/70">
                        <span className="h-[7px] w-[7px] animate-ghes-pulse rounded-full bg-persimmon" />
                        {city}
                        {freshness && ` · ${freshness}`}
                    </div>

                    <h1 className="mx-auto mt-7 max-w-[900px] font-display text-[2.25rem] font-extrabold leading-[1.06] tracking-[-0.028em] sm:text-6xl sm:leading-[1.03] lg:text-[82px]">
                        Orașul îți dă ghes.{' '}
                        {/* Hidden below `sm` so the phone wraps naturally instead
                            of orphaning "ghes." on its own line. */}
                        <br className="hidden sm:inline" />
                        Tu ce faci diseară?
                    </h1>

                    <p className="mx-auto mt-6 max-w-[620px] text-base leading-[1.65] text-ghost/60 sm:text-[19px]">
                        Pieptănăm {stats.sources ?? 0} surse din {city} non-stop, aruncăm
                        duplicatele și îți trimitem doar evenimentele care se potrivesc cu
                        tine. Fără reclame, fără evenimente promovate, fără scroll infinit.
                    </p>

                    <div className="mt-9 flex flex-col items-center justify-center gap-3.5 sm:flex-row">
                        <Link
                            href="/register"
                            className="rounded-full bg-persimmon px-8 py-4 text-base font-bold text-white transition-opacity hover:opacity-90"
                        >
                            Vreau un ghes
                        </Link>
                        <a
                            href="#evenimente"
                            className="rounded-full border border-ghost/20 px-7 py-4 text-base font-medium text-ghost transition-colors hover:border-ghost/50"
                        >
                            Vezi ce e diseară →
                        </a>
                    </div>

                    <p className="mt-[22px] text-[13px] text-ghost/40">
                        Gratuit. Fără card. Te dezabonezi din orice e-mail.
                    </p>
                </header>

                {/* ── STAT BAR ─────────────────────────────────────────── */}
                <Container className="mt-16 sm:mt-20">
                    <div className="grid grid-cols-2 border-t border-ghost/12 md:grid-cols-4">
                        <Stat
                            value={numberFormatter.format(stats.active ?? 0)}
                            label="evenimente active"
                        />
                        <Stat value={String(stats.sources ?? 0)} label="surse pieptănate" />
                        <Stat
                            value={String(stats.added_today ?? 0)}
                            label="adăugate azi"
                        />
                        <Stat value="0" label="reclame" accent />
                    </div>
                </Container>

                {/* ── EVENTS ───────────────────────────────────────────── */}
                <section
                    id="evenimente"
                    className="scroll-mt-8 bg-ghost py-16 text-navy sm:py-24"
                >
                    <Container>
                        <div className="flex flex-wrap items-end justify-between gap-8">
                            <div>
                                <Eyebrow>Diseară în {city}</Eyebrow>
                                <h2 className="mt-3.5 max-w-[620px] font-display text-3xl font-extrabold leading-[1.1] tracking-[-0.02em] sm:text-4xl lg:text-[44px]">
                                    Ce se întâmplă în oraș chiar acum
                                </h2>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                {datePills.map(({ label, href }) => (
                                    <Link
                                        key={label}
                                        href={href}
                                        className="inline-flex min-h-11 items-center rounded-full border border-navy/15 px-4 py-2.5 text-[13px] font-medium text-navy/65 transition-colors hover:border-navy/40 hover:text-navy sm:min-h-0"
                                    >
                                        {label}
                                    </Link>
                                ))}
                            </div>
                        </div>

                        {hasEvents ? (
                            <div className="mt-11 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                                {events.map((event) => (
                                    <PreviewCard key={event.id} event={event} />
                                ))}
                            </div>
                        ) : (
                            <div className="mt-11 rounded-[14px] border border-dashed border-navy/15 px-6 py-16 text-center">
                                <p className="font-display text-xl font-bold">
                                    Orașul doarme, dar noi nu.
                                </p>
                                <p className="mx-auto mt-3 max-w-[420px] text-[15px] leading-[1.7] text-navy/55">
                                    Căutăm următoarea ta ieșire. Revino în câteva ore — sau
                                    fă-ți cont și te anunțăm noi.
                                </p>
                            </div>
                        )}

                        <div className="mt-9 flex flex-wrap items-center justify-between gap-6 border-t border-navy/10 pt-6">
                            <p className="m-0 max-w-[620px] text-sm text-navy/50">
                                Lista publică e doar vârful. Cu un cont, feedul se rescrie
                                după gusturile tale și primești ce se potrivește înainte să
                                se umple.
                            </p>
                            <Link
                                href="/events"
                                className="inline-flex min-h-11 items-center whitespace-nowrap text-[15px] font-semibold text-navy hover:text-persimmon sm:min-h-0"
                            >
                                Vezi toate evenimentele →
                            </Link>
                        </div>
                    </Container>
                </section>

                {/* ── NOT A CALENDAR ───────────────────────────────────── */}
                <section className="pb-10 pt-16 sm:pt-24">
                    <Container>
                        <h2 className="m-0 max-w-[660px] font-display text-3xl font-extrabold leading-[1.1] tracking-[-0.02em] sm:text-4xl lg:text-[44px]">
                            Nu e un calendar. E cineva care caută în locul tău.
                        </h2>
                        <div className="mt-14 grid grid-cols-1 gap-10 md:grid-cols-3 lg:gap-14">
                            {[
                                {
                                    title: 'Un eveniment, o singură dată',
                                    body: 'Un concert anunțat pe patru site-uri îți apare o dată, cu toate sursele strânse la un loc. Tu alegi de unde iei biletul.',
                                    accent: true,
                                },
                                {
                                    title: 'Ce nu ajunge pe platformele de bilete',
                                    body: 'Pieptănăm site-uri de localuri, pagini de Facebook, calendare de teatre și galerii. Secretele orașului, deblocate.',
                                },
                                {
                                    title: 'Fără reclame, fără promovate',
                                    body: 'Nimeni nu plătește ca să apară mai sus. Ordinea e dată de gusturile tale, nu de buget. Ghes e un cadou pentru oraș.',
                                },
                            ].map(({ title, body, accent }) => (
                                <div
                                    key={title}
                                    className={`border-t-2 pt-[22px] ${
                                        accent ? 'border-persimmon' : 'border-ghost/15'
                                    }`}
                                >
                                    <h3 className="m-0 font-display text-xl font-bold leading-[1.3]">
                                        {title}
                                    </h3>
                                    <p className="mt-3.5 text-[15px] leading-[1.7] text-ghost/55">
                                        {body}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </Container>
                </section>

                {/* ── HOW IT WORKS ─────────────────────────────────────── */}
                <section
                    id="cum-functioneaza"
                    className="scroll-mt-8 pb-20 pt-14 sm:pb-26 sm:pt-20"
                >
                    <Container>
                        <Eyebrow>Cum funcționează</Eyebrow>
                        <div className="mt-10 grid grid-cols-1 gap-8 md:grid-cols-3">
                            {[
                                {
                                    step: '01',
                                    title: 'Spui ce-ți place',
                                    body: 'Cinci minute de conversație: muzica pe care o asculți, cartierele în care ieși, cât vrei să dai, în ce seri ești liber.',
                                },
                                {
                                    step: '02',
                                    title: 'Ghes pieptănă orașul 24/7',
                                    body: 'La fiecare câteva ore trecem prin toate sursele, unim duplicatele, punem categorii și etichete. Tu dormi, noi muncim.',
                                },
                                {
                                    step: '03',
                                    title: 'Primești doar ce contează',
                                    body: 'Un e-mail cu 5–8 evenimente, dintre care unul special ales ca să te scoată din rutină. Reacționezi, iar lista se ascute.',
                                },
                            ].map(({ step, title, body }) => (
                                <div
                                    key={step}
                                    className="rounded-2xl border border-ghost/12 p-8"
                                >
                                    <div className="font-display text-[40px] font-extrabold tracking-[-0.02em] text-persimmon">
                                        {step}
                                    </div>
                                    <h3 className="mt-4.5 font-display text-xl font-bold">
                                        {title}
                                    </h3>
                                    <p className="mt-3 text-[15px] leading-[1.7] text-ghost/55">
                                        {body}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </Container>
                </section>

                {/*
                  ── LIGHT BLOCK ────────────────────────────────────────
                  Screenshots, the digest sample and the FAQ share one ghost
                  ground; they are separated by hairline rules, not by a change
                  of background.
                */}
                <div className="bg-ghost text-navy">
                    {/* App screenshots */}
                    <section className="py-16 sm:py-24">
                        <Container>
                            <Eyebrow>Aplicația</Eyebrow>
                            <h2 className="mt-3.5 max-w-[620px] font-display text-3xl font-extrabold leading-[1.1] tracking-[-0.02em] sm:text-4xl lg:text-[44px]">
                                Feed, chat, eveniment. Nimic altceva.
                            </h2>
                            {/*
                              Phone screenshots are 9:16, so stacking three of
                              them costs ~1,900px of scroll on a phone. Swipe
                              through them instead; three-up from `sm`.
                            */}
                            <div className="mt-12 -mx-5 flex snap-x snap-mandatory gap-5 overflow-x-auto px-5 pb-2 sm:mx-0 sm:grid sm:grid-cols-3 sm:gap-8 sm:overflow-visible sm:px-0">
                                {[
                                    {
                                        slot: 'captură: feed personalizat',
                                        caption:
                                            'Feedul de recomandări, ordonat după cât se potrivește cu tine.',
                                    },
                                    {
                                        slot: 'captură: chat de onboarding',
                                        caption:
                                            'Conversația din care se naște profilul tău de interese.',
                                    },
                                    {
                                        slot: 'captură: pagina unui eveniment',
                                        caption:
                                            'Ora, locul, prețul și toate sursele care anunță evenimentul.',
                                    },
                                ].map(({ slot, caption }) => (
                                    <div
                                        key={slot}
                                        className="w-[68vw] shrink-0 snap-start sm:w-auto sm:shrink"
                                    >
                                        <Placeholder
                                            label={slot}
                                            className="aspect-[9/16] rounded-[22px] border border-navy/12"
                                        />
                                        <p className="mt-4 text-sm leading-[1.6] text-navy/55">
                                            {caption}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        </Container>
                    </section>

                    {/* Email digest */}
                    <section className="pb-16 sm:pb-24">
                        <Container>
                            <div className="grid grid-cols-1 items-center gap-10 border-t border-navy/10 pt-16 lg:grid-cols-2 lg:gap-16 lg:pt-20">
                                <div>
                                    <Eyebrow>E-mailul de seară</Eyebrow>
                                    <h2 className="mt-3.5 font-display text-3xl font-extrabold leading-[1.12] tracking-[-0.02em] sm:text-4xl lg:text-[40px]">
                                        Un e-mail care înlocuiește o oră de scroll
                                    </h2>
                                    <div className="mt-7 flex flex-col gap-4">
                                        {[
                                            '5–8 evenimente pe care le-ai fi căutat oricum, plus unul care te scoate din rutină.',
                                            'Reacționezi direct din e-mail. Fiecare apăsare ajustează ce primești mâine.',
                                            'Zilnic sau săptămânal, tu alegi. Sau notificări push, dacă preferi.',
                                        ].map((line) => (
                                            <div
                                                key={line}
                                                className="flex gap-3.5 text-[15px] leading-[1.6] text-navy/60"
                                            >
                                                <span className="font-bold text-persimmon">—</span>
                                                <span>{line}</span>
                                            </div>
                                        ))}
                                    </div>
                                </div>

                                {/* Mock digest email */}
                                <div className="overflow-hidden rounded-2xl border border-navy/10 bg-white shadow-[0_24px_48px_-24px_rgba(10,17,40,0.25)]">
                                    <div className="flex items-center gap-3 bg-navy px-6 py-5 text-ghost">
                                        <img
                                            src="/images/logo-dark.png"
                                            alt=""
                                            className="block h-[26px] w-[26px] rounded-md"
                                        />
                                        <div>
                                            <div className="font-display text-sm font-bold">
                                                Evenimentele tale pentru joi
                                            </div>
                                            <div className="mt-0.5 text-xs text-ghost/50">
                                                6 potriviri · 1 provocare
                                            </div>
                                        </div>
                                    </div>
                                    <div className="px-6 pb-6 pt-2">
                                        <div className="flex gap-4 border-b border-navy/8 py-5">
                                            <div
                                                className="h-[60px] w-[76px] flex-none rounded-lg"
                                                style={{ backgroundImage: HATCH }}
                                            />
                                            <div className="flex-1">
                                                <div className="font-display text-[15px] font-bold leading-[1.3]">
                                                    Balkan Taksim · concert
                                                </div>
                                                <div className="mt-1.5 text-[13px] text-navy/50">
                                                    Vineri, 20:00 · Faber · de la 60 lei
                                                </div>
                                                <div className="mt-3 flex flex-wrap gap-2">
                                                    <span className="rounded-full bg-persimmon px-2.5 py-1 text-[11px] font-semibold text-white">
                                                        Mă interesează
                                                    </span>
                                                    <span className="rounded-full border border-navy/15 px-2.5 py-1 text-[11px] font-medium text-navy/55">
                                                        Salvează
                                                    </span>
                                                    <span className="rounded-full border border-navy/15 px-2.5 py-1 text-[11px] font-medium text-navy/55">
                                                        Nu, mersi
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        <div className="flex gap-4 pb-1 pt-5">
                                            <div
                                                className="h-[60px] w-[76px] flex-none rounded-lg"
                                                style={{ backgroundImage: HATCH }}
                                            />
                                            <div className="flex-1">
                                                <div className="text-[10px] font-bold uppercase tracking-[0.14em] text-persimmon">
                                                    Ceva nou de încercat
                                                </div>
                                                <div className="mt-1.5 font-display text-[15px] font-bold leading-[1.3]">
                                                    Atelier de ceramică pentru începători
                                                </div>
                                                <div className="mt-1.5 text-[13px] text-navy/50">
                                                    Sâmbătă, 11:00 · Ambasada · 80 lei
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </Container>
                    </section>

                    {/* FAQ */}
                    <section id="intrebari" className="scroll-mt-8 pb-20 sm:pb-26">
                        <Container>
                            <div className="border-t border-navy/10 pt-16 lg:pt-20">
                                <h2 className="m-0 font-display text-3xl font-extrabold leading-[1.12] tracking-[-0.02em] sm:text-4xl lg:text-[40px]">
                                    Întrebări corecte, răspunsuri scurte
                                </h2>
                                <div className="mt-12 grid grid-cols-1 gap-10 md:grid-cols-2 lg:gap-x-16">
                                    {[
                                        {
                                            q: 'De unde luăm evenimentele?',
                                            a: `Din ${stats.sources ?? 0} surse din ${city}: site-uri de bilete, pagini de localuri și cluburi, calendare de teatre și galerii, evenimente de pe Facebook. Fiecare eveniment păstrează linkul către sursa originală.`,
                                        },
                                        {
                                            q: 'Ce face AI-ul, exact?',
                                            a: 'Citește titlul și descrierea, pune evenimentul într-o categorie, îi scoate etichete (jazz, vegan, stand-up), estimează prețul și recunoaște când două surse anunță același lucru. Nu scrie texte în locul organizatorului.',
                                        },
                                        {
                                            q: 'Ce date colectați despre mine?',
                                            a: 'E-mailul, orașul și profilul de interese pe care ți-l construiești în chat. Reacțiile la evenimente ajustează recomandările. Nu vindem nimic nimănui; îți poți exporta sau șterge datele oricând.',
                                        },
                                        {
                                            q: 'Cât de des primesc e-mailuri?',
                                            a: 'Zilnic sau săptămânal — tu alegi la înscriere și poți schimba oricând. Un e-mail, 5–8 evenimente. Zero e-mailuri de marketing.',
                                        },
                                        {
                                            q: `Doar ${city}?`,
                                            a: 'Deocamdată da. Preferăm un oraș acoperit complet decât zece pe jumătate. Urmează altele.',
                                        },
                                        {
                                            q: 'Pot adăuga propriul eveniment?',
                                            a: 'Da. Trimite-ne linkul și îl adăugăm. Dacă publici constant, îți legăm calendarul ca sursă și se preia automat, fără să mai trimiți nimic.',
                                        },
                                    ].map(({ q, a }) => (
                                        <div key={q}>
                                            <h3 className="m-0 font-display text-lg font-bold">
                                                {q}
                                            </h3>
                                            <p className="mt-2.5 text-[15px] leading-[1.7] text-navy/60">
                                                {a}
                                            </p>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </Container>
                    </section>
                </div>

                {/* ── ORGANISERS ───────────────────────────────────────── */}
                <section id="organizatori" className="scroll-mt-8 py-20 sm:py-26">
                    <Container>
                        <div className="flex flex-wrap items-end justify-between gap-10">
                            <div>
                                <Eyebrow>Pentru organizatori și localuri</Eyebrow>
                                <h2 className="mt-3.5 max-w-[620px] font-display text-3xl font-extrabold leading-[1.1] tracking-[-0.02em] sm:text-4xl lg:text-[44px]">
                                    Ai un eveniment? Îl vrem în Ghes.
                                </h2>
                            </div>
                            <a
                                href="mailto:salut@ghes.ro"
                                className="inline-flex min-h-11 items-center whitespace-nowrap rounded-full bg-persimmon px-6 py-3.5 text-[15px] font-semibold text-white transition-opacity hover:opacity-90"
                            >
                                Scrie-ne la salut@ghes.ro
                            </a>
                        </div>

                        {/* 1px grid gap over a lit background draws the hairlines. */}
                        <div className="mt-14 grid grid-cols-1 gap-px bg-ghost/12 md:grid-cols-3">
                            {[
                                {
                                    title: 'Trimite un eveniment',
                                    body: 'Un link e de-ajuns. Îl clasificăm și ajunge în fața oamenilor care au spus deja că le place genul ăsta.',
                                },
                                {
                                    title: 'Leagă-ți calendarul ca sursă',
                                    body: 'Dacă publici pe site sau pe Facebook, te adăugăm în lista de surse și preluăm automat. Nu mai trimiți nimic manual.',
                                },
                                {
                                    title: '„Ghes Approved”',
                                    body: 'Autocolant la intrare pentru localurile care țin orașul viu. Fără taxă, fără contract, fără poziții plătite.',
                                },
                            ].map(({ title, body }) => (
                                <div
                                    key={title}
                                    className="bg-navy p-8 md:first:pl-0 md:last:pr-0"
                                >
                                    <h3 className="m-0 font-display text-xl font-bold">
                                        {title}
                                    </h3>
                                    <p className="mt-3 text-[15px] leading-[1.7] text-ghost/55">
                                        {body}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </Container>
                </section>

                {/* ── FINAL CTA ────────────────────────────────────────── */}
                <section className="pb-24 pt-6 sm:pb-28">
                    <Container>
                        <div className="rounded-3xl border border-ghost/15 px-6 py-14 text-center sm:px-12 sm:py-[72px]">
                            <h2 className="m-0 font-display text-4xl font-extrabold leading-[1.05] tracking-[-0.028em] sm:text-5xl lg:text-[60px]">
                                Ce faci diseară?
                            </h2>
                            <p className="mx-auto mt-5 max-w-[520px] text-base leading-[1.6] text-ghost/60 sm:text-lg">
                                Cinci minute de chat, apoi orașul vine la tine. Gratuit, fără
                                reclame, pentru totdeauna.
                            </p>
                            <Link
                                href="/register"
                                className="mt-9 inline-block rounded-full bg-persimmon px-10 py-4.5 text-[17px] font-bold text-white transition-opacity hover:opacity-90"
                            >
                                Înregistrează-te gratuit
                            </Link>
                        </div>
                    </Container>
                </section>

                {/* ── FOOTER ───────────────────────────────────────────── */}
                <footer className="border-t border-ghost/10">
                    <Container className="flex flex-wrap items-center justify-between gap-8 py-9">
                        <div className="flex items-center gap-3">
                            <img
                                src="/images/logo-dark.png"
                                alt="Ghes"
                                className="block h-7 w-7 rounded-md opacity-85"
                            />
                            <span className="text-xs text-ghost/35">
                                © {new Date().getFullYear()} Ghes · Un cadou pentru oraș.
                            </span>
                        </div>
                        <div className="flex flex-wrap items-center gap-x-6 text-[13px] text-ghost/50">
                            <Link href="/events" className="inline-flex min-h-11 items-center hover:text-persimmon sm:min-h-0">
                                Evenimente
                            </Link>
                            <a href="#intrebari" className="inline-flex min-h-11 items-center hover:text-persimmon sm:min-h-0">
                                Întrebări
                            </a>
                            <Link href="/login" className="inline-flex min-h-11 items-center hover:text-persimmon sm:min-h-0">
                                Intră în cont
                            </Link>
                        </div>
                    </Container>
                </footer>
            </div>
        </>
    );
}
