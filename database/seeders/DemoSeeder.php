<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\EventCategory;
use App\Enums\Reaction;
use App\Models\Event;
use App\Models\EventBookmark;
use App\Models\User;
use App\Models\UserEventReaction;
use App\Services\Recommendation\DashboardStatsBuilder;
use App\Services\Recommendation\RecommendationEngine;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Populates a development database with enough data to render the dashboard.
 *
 * Not wired into DatabaseSeeder — run it explicitly:
 *
 *     php artisan db:seed --class=DemoSeeder
 *
 * Every event created here has to clear the same gates the recommendation
 * query applies (upcoming, visible, canonical, classified, matching city
 * slug), or the dashboard stays blank and the seeder looks broken.
 * Re-running is safe: the demo user and events are keyed on stable values.
 */
class DemoSeeder extends Seeder
{
    use WithoutModelEvents;

    public const DEMO_EMAIL = 'demo@ghes.test';

    private const DEMO_PASSWORD = 'password';

    /**
     * Every row this seeder creates is tagged with one of these, so a re-run
     * can remove exactly its own data and nothing else.
     */
    private const DEMO_URL_PREFIX = 'https://demo.ghes.test/events/';

    private const CROWD_EMAIL_DOMAIN = '@crowd.ghes.test';

    /**
     * Events the demo user has reacted to or saved are excluded from
     * recommendations, so the set is deliberately small.
     */
    private const ENGAGED_COUNT = 3;

    private const BOOKMARK_COUNT = 2;

    public function run(): void
    {
        $cityKey = (string) config('eventpulse.default_city', 'timisoara');
        $city = config("eventpulse.cities.{$cityKey}");

        // Fail loudly. A typo in EVENTPULSE_DEFAULT_CITY would otherwise seed
        // under a silently-invented label while the rest of the app resolves a
        // different one, and the mismatch only shows up as an empty dashboard.
        if (! is_array($city) || ! isset($city['label'])) {
            throw new RuntimeException(
                "eventpulse.cities.{$cityKey} is not configured — check EVENTPULSE_DEFAULT_CITY.",
            );
        }

        $cityLabel = (string) $city['label'];
        $timezone = (string) ($city['timezone'] ?? 'UTC');

        $this->purgePreviousRun();

        $demoUser = $this->createDemoUser($cityLabel);
        $events = $this->createEvents($cityLabel, $timezone);

        $this->createDemoEngagement($demoUser, $events);
        $this->createTrendingSignal($demoUser, $cityLabel, $events);

        $this->reportAndVerify($demoUser, $events->count(), $cityLabel);
    }

    /**
     * Re-run the gates the dashboard itself applies and report the real
     * numbers. Counting the blueprint array proves nothing: it is a constant,
     * so the seeder would announce success just as loudly if every event it
     * created were invisible to the recommendation query.
     */
    private function reportAndVerify(User $demoUser, int $created, string $cityLabel): void
    {
        $visible = app(DashboardStatsBuilder::class)->upcomingInUserCity($demoUser);
        $batch = app(RecommendationEngine::class)->recommend($demoUser);

        $message = sprintf(
            'Seeded %d events in %s (%d visible to the dashboard) and demo user %s (password: %s). '
                .'Recommendations: %d, discovery: %d.',
            $created,
            $cityLabel,
            $visible,
            self::DEMO_EMAIL,
            self::DEMO_PASSWORD,
            count($batch->recommendedEventIds),
            count($batch->discoveryEventIds),
        );

        if ($this->command !== null) {
            $this->command->info($message);
        }

        if ($visible === 0 || $batch->recommendedEventIds === []) {
            throw new RuntimeException(
                'DemoSeeder created events but the dashboard would still render empty. '
                    .$message,
            );
        }
    }

    /**
     * Remove only what a previous run of this seeder created, so re-running is
     * a refresh rather than a duplicate. Reactions, bookmarks and discovery
     * logs are cascade-deleted by their foreign keys.
     */
    private function purgePreviousRun(): void
    {
        Event::query()->where('source_url', 'like', self::DEMO_URL_PREFIX.'%')->delete();

        User::query()
            ->where('email', self::DEMO_EMAIL)
            ->orWhere('email', 'like', '%'.self::CROWD_EMAIL_DOMAIN)
            ->delete();
    }

    /**
     * A fully onboarded user with a hand-written profile, so recommendations
     * are explainable rather than the factory's random category map.
     */
    private function createDemoUser(string $cityLabel): User
    {
        /** @var User $user */
        $user = User::factory()->create([
            'name' => 'Demo Ghes',
            'email' => self::DEMO_EMAIL,
            'city' => $cityLabel,
            'onboarding_completed' => true,
            // Explicit, not the factory's random 0.1–0.9: at limit 8 this
            // yields 6 recommended + 2 discovery.
            'discovery_openness' => 0.25,
            'interest_profile' => [
                'music' => 0.92,
                'arts' => 0.78,
                'film' => 0.66,
                'food' => 0.61,
                'nightlife' => 0.55,
                'technology' => 0.48,
                'literature' => 0.40,
                'community' => 0.34,
                'education' => 0.22,
                'business' => 0.18,
                'family' => 0.15,
                'health' => 0.12,
                'sports' => 0.08,
                'tag:live-music' => 0.88,
                'tag:jazz' => 0.81,
                'tag:concert' => 0.74,
                'tag:teatru' => 0.62,
                'tag:expozitie' => 0.57,
                'tag:outdoor' => 0.44,
                'source:iabilet' => 0.70,
                'source:zilesinopti' => 0.55,
            ],
        ]);

        return $user;
    }

    /**
     * @return Collection<int, Event>
     */
    private function createEvents(string $cityLabel, string $timezone): Collection
    {
        $now = CarbonImmutable::now($timezone);

        return collect($this->eventBlueprints())
            ->map(function (array $blueprint, int $index) use ($cityLabel, $now): Event {
                $startsAt = $this->startsAt($now, $blueprint['days'], $blueprint['hour']);

                return Event::factory()->create([
                    'title' => $blueprint['title'],
                    'description' => $blueprint['description'],
                    'category' => $blueprint['category'],
                    'tags' => $blueprint['tags'],
                    'venue' => $blueprint['venue'],
                    'city' => $cityLabel,
                    'source' => $blueprint['source'],
                    // Stable key so re-running the seeder replaces rather than
                    // duplicates; EventFactory's default is a random unique URL.
                    'source_url' => self::DEMO_URL_PREFIX.($index + 1),
                    'source_id' => 'demo-'.($index + 1),
                    'starts_at' => $startsAt,
                    'ends_at' => $startsAt->addHours(3),
                    'is_free' => $blueprint['price'] === 0,
                    'price_min' => $blueprint['price'] === 0 ? null : $blueprint['price'],
                    'price_max' => $blueprint['price'] === 0 ? null : $blueprint['price'] + 40,
                    'popularity_score' => $blueprint['popularity'],
                    // EventFactory defaults to a via.placeholder.com URL, which
                    // no longer resolves — every card would show a broken image.
                    // EventCard renders a placeholder icon for null instead.
                    'image_url' => null,
                    // The four gates the recommendation query gets wrong if unset.
                    'is_classified' => true,
                    'is_geocoded' => true,
                    'is_enriched' => true,
                    'is_hidden' => false,
                    'merged_into_id' => null,
                ]);
            });
    }

    /**
     * Offsets land on the upcoming weekend where the blueprint asks for it, so
     * the dashboard's weekend section is never empty on a freshly seeded DB.
     *
     * This must agree with `ResolvesCity::weekendRange()`, which during a
     * weekend means the one *in progress*. `next(SATURDAY)` skips today, so
     * seeding on a Saturday would push every "saturday" blueprint a week out
     * and seeding on a Sunday would empty the section entirely — while the
     * seeder still printed a success line.
     */
    private function startsAt(CarbonImmutable $now, int|string $days, int $hour): CarbonImmutable
    {
        $saturday = $now->isSaturday() || $now->isSunday()
            ? $now->startOfWeek(CarbonImmutable::SATURDAY)
            : $now->next(CarbonImmutable::SATURDAY);

        if (! in_array($days, ['saturday', 'sunday'], true)) {
            return $now->addDays((int) $days)->setTime($hour, 0)->utc();
        }

        // Everything must be in the future: `upcoming()` is a strict
        // `starts_at > now()`, so a morning slot on a day already underway
        // would be seeded straight into a section that drops it. Slide to the
        // other weekend day, then to next weekend, rather than mashing the
        // event to "now + 2h" — that produced a 9am yoga class at 20:42 and
        // stacked several events on an identical timestamp.
        $candidates = $days === 'saturday'
            ? [$saturday, $saturday->addDay(), $saturday->addWeek()]
            : [$saturday->addDay(), $saturday->addWeek(), $saturday->addWeek()->addDay()];

        foreach ($candidates as $candidate) {
            $at = $candidate->setTime($hour, 0);

            if ($at->isAfter($now)) {
                return $at->utc();
            }
        }

        return $saturday->addWeek()->setTime($hour, 0)->utc();
    }

    /**
     * Reactions and bookmarks for the demo user so cards render their
     * "interested" and "saved" state instead of a uniform default.
     *
     * @param  Collection<int, Event>  $events
     */
    private function createDemoEngagement(User $user, Collection $events): void
    {
        $events->take(self::ENGAGED_COUNT)->each(function (Event $event, int $index) use ($user): void {
            UserEventReaction::query()->updateOrCreate(
                ['user_id' => $user->id, 'event_id' => $event->id],
                [
                    'reaction' => $index === 0 ? Reaction::NotInterested : Reaction::Interested,
                    'is_processed' => true,
                ],
            );
        });

        $events->slice(self::ENGAGED_COUNT, self::BOOKMARK_COUNT)->each(
            fn (Event $event) => EventBookmark::query()->updateOrCreate(
                ['user_id' => $user->id, 'event_id' => $event->id],
                ['is_processed' => true],
            ),
        );
    }

    /**
     * DiscoveryEngine::trendingEvents needs at least `trending_min_reactions`
     * *distinct* users engaging within the trending window, and it skips
     * anything the demo user already touched. Without these extra users the
     * trending discovery path is dead and only categoryDiscovery ever fires.
     *
     * @param  Collection<int, Event>  $events
     */
    private function createTrendingSignal(User $demoUser, string $cityLabel, Collection $events): void
    {
        $engagedIds = $demoUser->reactions()->pluck('event_id')
            ->merge($demoUser->bookmarks()->pluck('event_id'));

        $required = (int) config('eventpulse.discovery.trending_min_reactions', 3);

        $crowd = collect(range(1, $required + 1))->map(
            fn (int $i) => User::factory()->create([
                'name' => 'Demo Crowd '.$i,
                'email' => 'crowd-'.$i.self::CROWD_EMAIL_DOMAIN,
                'city' => $cityLabel,
                'onboarding_completed' => true,
            ]),
        );

        // Trending must land on events the demo user is unlikely to be
        // *recommended*, because RecommendationEngine now excludes recommended
        // ids from discovery — signal on a high-interest event would be
        // silently discarded and the trending path would look dead. Sports and
        // health sit lowest in the demo profile, so they stay available.
        $trending = $events
            ->filter(fn (Event $event) => in_array(
                $event->category,
                [EventCategory::Sports, EventCategory::Health],
                true,
            ))
            ->reject(fn (Event $event) => $engagedIds->contains($event->id))
            ->take(3);

        foreach ($trending as $event) {
            foreach ($crowd as $member) {
                UserEventReaction::query()->updateOrCreate(
                    ['user_id' => $member->id, 'event_id' => $event->id],
                    ['reaction' => Reaction::Interested, 'is_processed' => true],
                );
            }
        }
    }

    /**
     * Realistic Timișoara venues and events across every category, spread over
     * the next month. `days` accepts an int offset or 'saturday'/'sunday'.
     *
     * @return list<array{title: string, description: string, category: EventCategory, tags: list<string>, venue: string, source: string, days: int|string, hour: int, price: int, popularity: int}>
     */
    private function eventBlueprints(): array
    {
        return [
            ['title' => 'Concert simfonic: Enescu și Ceaikovski', 'description' => 'Filarmonica Banatul deschide stagiunea cu Rapsodia Română nr. 1 și Simfonia a V-a de Ceaikovski, sub bagheta dirijorului invitat.', 'category' => EventCategory::Music, 'tags' => ['concert', 'clasica', 'live-music'], 'venue' => 'Filarmonica Banatul', 'source' => 'iabilet', 'days' => 'saturday', 'hour' => 19, 'price' => 60, 'popularity' => 88],
            ['title' => 'Jazz în Podul Vechi', 'description' => 'Seară de jazz manouche cu un trio local, într-un spațiu intim cu doar 40 de locuri.', 'category' => EventCategory::Music, 'tags' => ['jazz', 'live-music', 'concert'], 'venue' => 'Scârț Loc Lejer', 'source' => 'zilesinopti', 'days' => 3, 'hour' => 21, 'price' => 40, 'popularity' => 72],
            ['title' => 'Balkan Brass Night', 'description' => 'Fanfară balcanică live, dans până dimineața în curtea din spate.', 'category' => EventCategory::Music, 'tags' => ['live-music', 'outdoor', 'concert'], 'venue' => 'Ambasada', 'source' => 'zilesinopti', 'days' => 'sunday', 'hour' => 20, 'price' => 35, 'popularity' => 76],
            ['title' => 'Recital de pian: Chopin integral', 'description' => 'Un recital dedicat nocturnelor lui Chopin, susținut de un pianist bursier al Filarmonicii.', 'category' => EventCategory::Music, 'tags' => ['clasica', 'concert'], 'venue' => 'Casa Artelor', 'source' => 'iabilet', 'days' => 12, 'hour' => 18, 'price' => 45, 'popularity' => 54],

            ['title' => 'Traviata', 'description' => 'Opera lui Verdi în producția Operei Naționale Române Timișoara, cu orchestră și cor complet.', 'category' => EventCategory::Arts, 'tags' => ['opera', 'teatru'], 'venue' => 'Opera Națională Română', 'source' => 'opera_timisoara', 'days' => 'saturday', 'hour' => 18, 'price' => 70, 'popularity' => 91],
            ['title' => 'O scrisoare pierdută', 'description' => 'Caragiale în regie contemporană, pe scena mare a Teatrului Național.', 'category' => EventCategory::Arts, 'tags' => ['teatru'], 'venue' => 'Teatrul Național „Mihai Eminescu”', 'source' => 'teatru_national_tm', 'days' => 5, 'hour' => 19, 'price' => 50, 'popularity' => 80],
            ['title' => 'Expoziție: Banatul interbelic în fotografie', 'description' => 'Peste 120 de fotografii de arhivă din colecția Muzeului Național al Banatului. Intrare liberă.', 'category' => EventCategory::Arts, 'tags' => ['expozitie', 'free'], 'venue' => 'Muzeul de Artă Timișoara', 'source' => 'visit_timisoara', 'days' => 2, 'hour' => 10, 'price' => 0, 'popularity' => 63],
            ['title' => 'Atelier de ceramică pentru începători', 'description' => 'Trei ore la roata olarului, materiale incluse. Grup de maximum 10 persoane.', 'category' => EventCategory::Arts, 'tags' => ['workshop', 'expozitie'], 'venue' => 'Reciproc', 'source' => 'generic_html', 'days' => 9, 'hour' => 17, 'price' => 120, 'popularity' => 41],

            ['title' => 'Street Food Festival Timișoara', 'description' => 'Peste 30 de food truck-uri în Parcul Rozelor, muzică live și zonă pentru copii.', 'category' => EventCategory::Food, 'tags' => ['food', 'outdoor', 'family-friendly'], 'venue' => 'Parcul Rozelor', 'source' => 'zilesinopti', 'days' => 'saturday', 'hour' => 12, 'price' => 0, 'popularity' => 94],
            ['title' => 'Degustare de vinuri din Banat', 'description' => 'Șase crame locale, degustare ghidată de un somelier, farfurie de brânzeturi inclusă.', 'category' => EventCategory::Food, 'tags' => ['food', 'workshop'], 'venue' => 'Cramele Recaș — Wine Shop', 'source' => 'iabilet', 'days' => 7, 'hour' => 19, 'price' => 90, 'popularity' => 58],
            ['title' => 'Brunch cu producători locali', 'description' => 'Piață de producători și brunch în curtea Faber, în fiecare duminică.', 'category' => EventCategory::Food, 'tags' => ['food', 'outdoor', 'family-friendly'], 'venue' => 'Faber', 'source' => 'generic_html', 'days' => 'sunday', 'hour' => 11, 'price' => 0, 'popularity' => 67],

            ['title' => 'Techno la Capcana', 'description' => 'Line-up cu trei DJ din scena locală, până la 5 dimineața.', 'category' => EventCategory::Nightlife, 'tags' => ['nightlife', 'live-music'], 'venue' => 'Capcana', 'source' => 'zilesinopti', 'days' => 'saturday', 'hour' => 23, 'price' => 30, 'popularity' => 70],
            ['title' => 'Karaoke Night', 'description' => 'Seară de karaoke cu premii pentru cele mai curajoase interpretări.', 'category' => EventCategory::Nightlife, 'tags' => ['nightlife'], 'venue' => 'D\'Arc', 'source' => 'generic_html', 'days' => 4, 'hour' => 21, 'price' => 0, 'popularity' => 45],
            ['title' => 'Vinyl Session: soul & funk', 'description' => 'Numai vinil, numai soul și funk, selecție de la doi colecționari din oraș.', 'category' => EventCategory::Nightlife, 'tags' => ['nightlife', 'live-music'], 'venue' => 'Aethernativ', 'source' => 'zilesinopti', 'days' => 11, 'hour' => 22, 'price' => 20, 'popularity' => 52],

            ['title' => 'Timișoara JS Meetup #42', 'description' => 'Două prezentări despre React Server Components și testare end-to-end, urmate de networking cu pizza.', 'category' => EventCategory::Technology, 'tags' => ['tech', 'networking', 'free'], 'venue' => 'Cowork Timișoara', 'source' => 'meetup', 'days' => 6, 'hour' => 18, 'price' => 0, 'popularity' => 61],
            ['title' => 'Workshop: introducere în Kubernetes', 'description' => 'Zi completă, hands-on, laptop obligatoriu. Se lucrează pe un cluster real.', 'category' => EventCategory::Technology, 'tags' => ['tech', 'workshop'], 'venue' => 'UPT — Facultatea de Automatică', 'source' => 'eventbrite', 'days' => 14, 'hour' => 9, 'price' => 250, 'popularity' => 49],
            ['title' => 'AI & Robotics Night', 'description' => 'Demo-uri live de la trei laboratoare din Timișoara și o sesiune de întrebări deschise.', 'category' => EventCategory::Technology, 'tags' => ['tech', 'networking'], 'venue' => 'Iulius Congress Hall', 'source' => 'meetup', 'days' => 18, 'hour' => 18, 'price' => 0, 'popularity' => 57],

            ['title' => 'Startup Pitch Night Timișoara', 'description' => 'Opt echipe la început de drum, cinci minute fiecare, juriu format din investitori locali.', 'category' => EventCategory::Business, 'tags' => ['startup', 'networking'], 'venue' => 'Iulius Congress Hall', 'source' => 'eventbrite', 'days' => 8, 'hour' => 18, 'price' => 0, 'popularity' => 55],
            ['title' => 'Conferință: viitorul industriei din Banat', 'description' => 'Panel cu producători, universități și administrație locală despre automatizare și forță de muncă.', 'category' => EventCategory::Business, 'tags' => ['networking', 'workshop'], 'venue' => 'Centrul Regional de Afaceri', 'source' => 'eventbrite', 'days' => 21, 'hour' => 10, 'price' => 150, 'popularity' => 38],

            ['title' => 'Proiecție: Cinema Paradiso (restaurat 4K)', 'description' => 'Clasicul lui Tornatore, copie restaurată, cu introducere din partea unui critic de film.', 'category' => EventCategory::Film, 'tags' => ['film'], 'venue' => 'Cinema Victoria', 'source' => 'iabilet', 'days' => 'sunday', 'hour' => 19, 'price' => 25, 'popularity' => 69],
            ['title' => 'Serile Filmului Românesc', 'description' => 'Trei scurtmetraje premiate, urmate de discuție cu regizorii.', 'category' => EventCategory::Film, 'tags' => ['film', 'free'], 'venue' => 'Cinema Timiș', 'source' => 'timisoreni', 'days' => 10, 'hour' => 20, 'price' => 0, 'popularity' => 47],
            ['title' => 'Film în aer liber: Amélie', 'description' => 'Proiecție în curte, pături și șezlonguri asigurate. În caz de ploaie se mută în sală.', 'category' => EventCategory::Film, 'tags' => ['film', 'outdoor'], 'venue' => 'Bastionul Theresia', 'source' => 'visit_timisoara', 'days' => 16, 'hour' => 21, 'price' => 20, 'popularity' => 64],

            ['title' => 'Lansare de carte: poezie contemporană', 'description' => 'Lectură publică și discuție cu autorul, moderată de un redactor de la o revistă locală.', 'category' => EventCategory::Literature, 'tags' => ['carte', 'free'], 'venue' => 'Librăria La Două Bufnițe', 'source' => 'timisoreni', 'days' => 3, 'hour' => 18, 'price' => 0, 'popularity' => 36],
            ['title' => 'Club de lectură: literatură central-europeană', 'description' => 'Discuție lunară deschisă oricui a citit cartea. Luna aceasta: Danubiu de Claudio Magris.', 'category' => EventCategory::Literature, 'tags' => ['carte', 'free'], 'venue' => 'Librăria La Două Bufnițe', 'source' => 'generic_html', 'days' => 17, 'hour' => 19, 'price' => 0, 'popularity' => 29],

            ['title' => 'Semimaratonul Timișoara', 'description' => 'Traseu de 21 km prin centrul istoric, cu curse de 5 și 10 km pentru amatori.', 'category' => EventCategory::Sports, 'tags' => ['outdoor', 'sport'], 'venue' => 'Piața Victoriei', 'source' => 'generic_html', 'days' => 'sunday', 'hour' => 9, 'price' => 80, 'popularity' => 83],
            ['title' => 'Meci: ACS Poli Timișoara', 'description' => 'Etapă de campionat pe stadionul Dan Păltinișanu.', 'category' => EventCategory::Sports, 'tags' => ['sport', 'outdoor'], 'venue' => 'Stadionul Dan Păltinișanu', 'source' => 'iabilet', 'days' => 13, 'hour' => 17, 'price' => 30, 'popularity' => 74],
            ['title' => 'Ture de bicicletă pe malul Begăi', 'description' => 'Tură relaxată de 15 km, ritm de conversație, deschisă tuturor vârstelor.', 'category' => EventCategory::Sports, 'tags' => ['outdoor', 'sport', 'free'], 'venue' => 'Splaiul Tudor Vladimirescu', 'source' => 'generic_html', 'days' => 20, 'hour' => 10, 'price' => 0, 'popularity' => 42],

            ['title' => 'Yoga în Parcul Central', 'description' => 'Sesiune de vinyasa pentru toate nivelurile. Se aduce saltea proprie.', 'category' => EventCategory::Health, 'tags' => ['outdoor', 'free'], 'venue' => 'Parcul Central', 'source' => 'generic_html', 'days' => 'saturday', 'hour' => 9, 'price' => 0, 'popularity' => 50],
            ['title' => 'Atelier de respirație și management al stresului', 'description' => 'Tehnici practice pentru zilele aglomerate, susținut de un psihoterapeut.', 'category' => EventCategory::Health, 'tags' => ['workshop'], 'venue' => 'Casa Tineretului', 'source' => 'eventbrite', 'days' => 15, 'hour' => 18, 'price' => 100, 'popularity' => 31],

            ['title' => 'Atelier de robotică pentru copii (7–12 ani)', 'description' => 'Copiii construiesc și programează un robot simplu. Părinții pot rămâne în sală.', 'category' => EventCategory::Family, 'tags' => ['family-friendly', 'workshop', 'tech'], 'venue' => 'Muzeul Copiilor', 'source' => 'generic_html', 'days' => 'saturday', 'hour' => 11, 'price' => 60, 'popularity' => 59],
            ['title' => 'Teatru de păpuși: Capra cu trei iezi', 'description' => 'Spectacol de 45 de minute pentru cei mici, urmat de vizită în culise.', 'category' => EventCategory::Family, 'tags' => ['family-friendly', 'teatru'], 'venue' => 'Teatrul pentru Copii și Tineret Merlin', 'source' => 'iabilet', 'days' => 'sunday', 'hour' => 11, 'price' => 25, 'popularity' => 66],

            ['title' => 'Curs gratuit de limba română pentru străini', 'description' => 'Nivel începător, opt sesiuni săptămânale. Înscriere necesară.', 'category' => EventCategory::Education, 'tags' => ['workshop', 'free'], 'venue' => 'Biblioteca Județeană Timiș', 'source' => 'generic_html', 'days' => 6, 'hour' => 17, 'price' => 0, 'popularity' => 33],
            ['title' => 'Prelegere: istoria arhitecturii timișorene', 'description' => 'De la baroc la modernismul interbelic, cu proiecții de arhivă.', 'category' => EventCategory::Education, 'tags' => ['expozitie', 'free'], 'venue' => 'Universitatea de Vest', 'source' => 'timisoreni', 'days' => 19, 'hour' => 18, 'price' => 0, 'popularity' => 37],

            ['title' => 'Curățenie comunitară pe malul Begăi', 'description' => 'Acțiune de voluntariat, mănuși și saci asigurați. Se încheie cu o limonadă.', 'category' => EventCategory::Community, 'tags' => ['outdoor', 'free'], 'venue' => 'Malul Begăi — zona Elisabetin', 'source' => 'generic_html', 'days' => 'saturday', 'hour' => 10, 'price' => 0, 'popularity' => 44],
            ['title' => 'Târg de vechituri și schimb de haine', 'description' => 'Adu ce nu mai porți, pleacă cu altceva. Intrare liberă.', 'category' => EventCategory::Community, 'tags' => ['outdoor', 'free', 'family-friendly'], 'venue' => 'Piața Traian', 'source' => 'zilesinopti', 'days' => 'sunday', 'hour' => 10, 'price' => 0, 'popularity' => 56],
            ['title' => 'Seară de jocuri de societate', 'description' => 'Peste 200 de jocuri disponibile, explicate de gazde. Vino singur sau cu prietenii.', 'category' => EventCategory::Community, 'tags' => ['family-friendly'], 'venue' => 'Faber', 'source' => 'generic_html', 'days' => 5, 'hour' => 19, 'price' => 15, 'popularity' => 48],

            ['title' => 'Tur ghidat: Timișoara subterană', 'description' => 'Vizită în galeriile Bastionului și în adăposturile din centrul vechi.', 'category' => EventCategory::Other, 'tags' => ['outdoor'], 'venue' => 'Bastionul Theresia', 'source' => 'visit_timisoara', 'days' => 22, 'hour' => 16, 'price' => 40, 'popularity' => 53],
            ['title' => 'Observație astronomică publică', 'description' => 'Telescoape instalate în parc, ghidaj de la clubul local de astronomie. Depinde de vreme.', 'category' => EventCategory::Other, 'tags' => ['outdoor', 'free'], 'venue' => 'Parcul Botanic', 'source' => 'generic_html', 'days' => 24, 'hour' => 21, 'price' => 0, 'popularity' => 39],
        ];
    }
}
