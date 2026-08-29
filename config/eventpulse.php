<?php

declare(strict_types=1);
use App\Services\Scraping\Adapters\AllEventsScraper;
use App\Services\Scraping\Adapters\EntertixScraper;
use App\Services\Scraping\Adapters\EventbriteScraper;
use App\Services\Scraping\Adapters\FacebookEventsScraper;
use App\Services\Scraping\Adapters\GenericHtmlScraper;
use App\Services\Scraping\Adapters\GoogleEventsScraper;
use App\Services\Scraping\Adapters\IaBiletScraper;
use App\Services\Scraping\Adapters\MeetupScraper;
use App\Services\Scraping\Adapters\OnEventScraper;
use App\Services\Scraping\Adapters\OperaTimisoaraScraper;
use App\Services\Scraping\Adapters\TeatruNationalTmScraper;
use App\Services\Scraping\Adapters\TimisoreniScraper;
use App\Services\Scraping\Adapters\VisitTimisoaraScraper;
use App\Services\Scraping\Adapters\ZileSiNoptiScraper;

return [
    'admin_emails' => array_filter(explode(',', (string) env('EVENTPULSE_ADMIN_EMAILS', ''))),

    'logging' => [
        // Channel the artisan commands mirror their terminal output to.
        // Empty falls back to the application's default log channel.
        'console_channel' => env('EVENTPULSE_CONSOLE_LOG_CHANNEL'),
    ],

    'recommendation' => [
        // Must sum to 1.0 — scoreEvent() is a plain weighted sum, not a
        // normalised average, so a set that sums to less silently caps every
        // event below 1.0.
        'weights' => [
            'category' => 0.28,
            'tags' => 0.22,
            'location' => 0.15,
            'time' => 0.10,
            'price' => 0.05,
            'freshness' => 0.05,
            'popularity' => 0.10,
            // How much the providers that reported an event count. Deliberately
            // small: a source is a proxy for taste, not taste itself, and the
            // big aggregators list everything.
            'source' => 0.05,
        ],

        // Event-to-event similarity for the "related events" strip on the event
        // detail page. Deliberately plain integer points rather than the
        // normalised [0,1] weights above: nothing consumes the value except an
        // ordering, so a readable scale beats a calibrated one.
        'related' => [
            'limit' => 6,
            // Candidates pulled from the database before scoring in PHP.
            'candidate_limit' => 100,
            // Tags beyond this are dropped from the candidate query, to keep
            // the OR chain bounded for an over-tagged event.
            'max_tags_considered' => 10,
            'points' => [
                'category' => 3,
                'tag' => 2,
                // Cap on the total contributed by shared tags, so tag overlap
                // cannot drown out every other signal.
                'tag_cap' => 6,
                'venue' => 2,
                'city' => 1,
                'date_proximity' => 1,
            ],
            // Days between the two start times for `date_proximity` to apply.
            'date_proximity_days' => 14,
        ],
    ],
    'experiments' => [
        // A/B variants for recommendation scoring weights. Each user is assigned
        // one variant deterministically; RecommendationEngine scores with it.
        // 'control' mirrors recommendation.weights.
        'recommendation_weights' => [
            'control' => [
                'category' => 0.28,
                'tags' => 0.22,
                'location' => 0.15,
                'time' => 0.10,
                'price' => 0.05,
                'freshness' => 0.05,
                'popularity' => 0.10,
                'source' => 0.05,
            ],
            'freshness_boost' => [
                'category' => 0.25,
                'tags' => 0.18,
                'location' => 0.15,
                'time' => 0.10,
                'price' => 0.05,
                'freshness' => 0.15,
                'popularity' => 0.07,
                'source' => 0.05,
            ],
        ],
    ],
    'feedback' => [
        // Per-signal profile adjustments: distinct deltas for the event's
        // category score and for each of its tag scores.
        //
        // Only "interested" and "not_interested" are Reaction cases. "saved" is
        // the bookmark signal (event_bookmarks) — it stacks with a reaction
        // rather than replacing it, and is still the strongest positive signal
        // (an explicit bookmark > a thumbs-up). "ignored" is a passive outcome
        // applied to un-reacted events in an ageing notification.
        // The `source` delta moves one "source:{provider}" key per provider that
        // reported the event. It is the weakest of the three on purpose: which
        // site listed an event says far less about the user than what the event
        // is, and an aggregator that lists everything would otherwise drown out
        // the category and tag signal.
        'deltas' => [
            'interested' => ['category' => 0.15, 'tag' => 0.20, 'source' => 0.05],
            'saved' => ['category' => 0.20, 'tag' => 0.25, 'source' => 0.07],
            'not_interested' => ['category' => -0.15, 'tag' => -0.20, 'source' => -0.05],
            'ignored' => ['category' => -0.02, 'tag' => 0.0, 'source' => -0.01],
        ],
        // A notification must be at least this old before its un-reacted events
        // are treated as "ignored" and passively decayed.
        'ignored_window_hours' => (int) env('EVENTPULSE_IGNORED_WINDOW_HOURS', 72),
    ],
    'discovery' => [
        'default_openness' => 0.3,
        'exploration_budget' => 0.2,
        'min_surprise_score' => 0.3,
        // Exploration reward/penalty: positive reactions to a discovery event
        // boost the profile more, negative reactions penalise it less, than normal.
        'reward_multiplier' => 1.5,
        'penalty_multiplier' => 0.5,
        // Serendipity decay: a category surfaced this many times with zero positive
        // outcomes is suppressed from discovery for this many days.
        'suppression_threshold' => 3,
        'suppression_days' => 30,
        // discovery_openness auto-tuning: once a user has resolved at least
        // min_samples discovery events, if their hit rate falls below the
        // threshold, reduce openness by step (never below floor).
        'openness_min_samples' => 5,
        'openness_hit_rate_threshold' => 0.1,
        'openness_step' => 0.05,
        'openness_floor' => 0.05,
        // Trending injection: events with at least this many positive reactions
        // across the platform (within the window) fill reserved discovery slots.
        'trending_min_reactions' => 3,
        'trending_window_days' => 14,
        'trending_slots' => 1,
        // Collaborative filtering: "similar users" share an interest category at
        // or above this score; discovery is biased toward categories they react
        // to positively.
        'similar_user_threshold' => 0.6,
        'similar_user_limit' => 200,
    ],
    'geocoding' => [
        // Provider used to resolve an address into coordinates: 'nominatim' or 'google'.
        'provider' => env('GEOCODING_PROVIDER', 'nominatim'),
        'google_key' => env('GOOGLE_GEOCODING_KEY'),
        'nominatim_url' => env('NOMINATIM_URL', 'https://nominatim.openstreetmap.org/search'),
        // Nominatim's usage policy requires an identifying User-Agent.
        'user_agent' => env('GEOCODING_USER_AGENT', 'Ghes/1.0 (+https://ghes.app)'),
        'timeout_seconds' => (int) env('GEOCODING_TIMEOUT_SECONDS', 10),
    ],
    'enrichment' => [
        'timeout_seconds' => (int) env('EVENTPULSE_ENRICHMENT_TIMEOUT', 10),
    ],
    'push' => [
        'enabled' => (bool) env('WEBPUSH_ENABLED', false),
        'vapid' => [
            'subject' => env('WEBPUSH_VAPID_SUBJECT', 'mailto:events@ghes.app'),
            'public_key' => env('WEBPUSH_VAPID_PUBLIC_KEY'),
            'private_key' => env('WEBPUSH_VAPID_PRIVATE_KEY'),
        ],
    ],
    'dedup' => [
        'enabled' => (bool) env('EVENTPULSE_DEDUP_ENABLED', true),

        // How far either side of an event's local date to look for candidates.
        // One day absorbs sources that publish an after-midnight event under
        // the previous day's listing.
        'match_window_days' => 1,

        // Minimum combined score for two events to be considered the same.
        'min_score' => 0.75,

        // The title component must clear this on its own, so venue and date
        // agreement can never merge two different acts at the same venue.
        'min_title_similarity' => 0.60,

        // Titles reducing to fewer significant tokens than this ("Concert",
        // "Petrecere") are too generic to merge on the blocking key alone and
        // must go through the scored path instead.
        'min_title_tokens_for_key_match' => 2,

        'weights' => [
            'title' => 0.60,
            'venue' => 0.25,
            'time' => 0.15,
        ],

        // Upper bound on how many same-city, same-window events are scored.
        'max_candidates' => 200,

        'title_noise_words' => [
            'live', 'official', 'oficial', 'event', 'events', 'eveniment', 'evenimente',
            'bilete', 'tickets', 'la', 'in', 'the', 'cu', 'si', 'de',
        ],

        'title_separators' => [' @ ', ' // ', ' | '],

        // Which source wins when two of them disagree on a field. Official
        // venue sites outrank ticketing, which outranks aggregators.
        'source_priority' => [
            'opera_timisoara' => 100,
            'teatru_national_tm' => 100,
            'entertix' => 80,
            'iabilet' => 80,
            'eventbrite' => 80,
            'zilesinopti' => 60,
            'timisoreni' => 60,
            'onevent' => 60,
            'radio_timisoara' => 50,
            'allevents' => 40,
            'meetup' => 40,
            'visit_timisoara' => 40,
            'facebook_events' => 30,
            'google_events' => 20,
            'generic_html' => 10,
        ],
        'default_source_priority' => 10,

        'lock_seconds' => 10,
        'lock_wait_seconds' => 5,
    ],

    'scraping' => [
        'interval_hours' => (int) env('EVENTPULSE_SCRAPE_INTERVAL_HOURS', 4),
        'max_consecutive_failures' => 3,
        'timeout_seconds' => 30,
    ],
    'scrapers' => [
        'user_agents' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:123.0) Gecko/20100101 Firefox/123.0',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_3_1) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.3.1 Safari/605.1.15',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36 Edg/121.0.0.0',
        ],
        'request_delay' => [2, 5],
        'max_pages' => 10,
        // Cache page responses in local/testing env to avoid hammering the site on repeated runs.
        // Set to 0 to disable. Responses are cached in the default cache store.
        'cache_ttl_minutes' => (int) env('SCRAPER_CACHE_TTL_MINUTES', 60),
    ],

    'adapter_registry' => [
        'allevents' => AllEventsScraper::class,
        'entertix' => EntertixScraper::class,
        'eventbrite' => EventbriteScraper::class,
        'iabilet' => IaBiletScraper::class,
        'onevent' => OnEventScraper::class,
        'opera_timisoara' => OperaTimisoaraScraper::class,
        'teatru_national_tm' => TeatruNationalTmScraper::class,
        'timisoreni' => TimisoreniScraper::class,
        'meetup' => MeetupScraper::class,
        'visit_timisoara' => VisitTimisoaraScraper::class,
        'zilesinopti' => ZileSiNoptiScraper::class,
        'facebook_events' => FacebookEventsScraper::class,
        'generic_html' => GenericHtmlScraper::class,
        'google_events' => GoogleEventsScraper::class,
    ],

    'cities' => [
        'timisoara' => [
            'label' => 'Timișoara',
            'timezone' => 'Europe/Bucharest',
            'coordinates' => [45.7489, 21.2087],
            'radius_km' => 25,
            'sources' => [
                [
                    'adapter' => 'zilesinopti',
                    'url' => 'https://zilesinopti.ro/evenimente-timisoara/',
                    'extra_urls' => ['https://zilesinopti.ro/evenimente-timisoara-weekend/'],
                    'enabled' => true,
                    'interval_hours' => 4,
                ],
                ['adapter' => 'iabilet',        'url' => 'https://m.iabilet.ro/bilete-in-timisoara/',              'enabled' => true, 'interval_hours' => 4],
                ['adapter' => 'allevents',       'url' => 'https://allevents.in/timisoara/all',                     'enabled' => true, 'interval_hours' => 6],
                ['adapter' => 'eventbrite',      'params' => ['address' => 'Timisoara,Romania'],                    'enabled' => true, 'interval_hours' => 6],
                ['adapter' => 'onevent',         'url' => 'https://www.onevent.ro/orase/timisoara/',                'enabled' => true, 'interval_hours' => 6],
                ['adapter' => 'timisoreni', 'url' => 'https://www.timisoreni.ro/info/index/t--evenimente/', 'extra_urls' => ['https://www.timisoreni.ro/info/spectacole/'], 'enabled' => true, 'interval_hours' => 8],
                ['adapter' => 'opera_timisoara', 'url' => 'https://www.ort.ro/ro/Spectacole.html',                   'enabled' => true, 'interval_hours' => 24],
                ['adapter' => 'teatru_national_tm', 'url' => 'https://www.tntm.ro/',                                   'enabled' => true, 'interval_hours' => 24],
                ['adapter' => 'entertix', 'url' => 'https://www.entertix.ro/evenimente', 'city_filter' => 'Timișoara', 'enabled' => true, 'interval_hours' => 8],
                ['adapter' => 'visit_timisoara', 'url' => 'https://visit-timisoara.com/events-activities/',         'enabled' => true, 'interval_hours' => 12],
                ['adapter' => 'meetup',          'url' => 'https://www.meetup.com/find/ro--timisoara/',             'enabled' => true, 'interval_hours' => 6],
                [
                    'adapter' => 'facebook_events',
                    'enabled' => true,
                    'interval_hours' => 12,
                    'params' => [
                        'apify_actor' => 'apify/facebook-events-scraper',
                        'apify_queries' => [
                            'events in Timisoara',
                            'evenimente Timisoara',
                            'concerte Timisoara',
                            'petreceri Timisoara',
                        ],
                        'facebook_pages' => [
                            'https://www.facebook.com/evenimente.timis/events/',
                            'https://www.facebook.com/VisitTimisoara/events/',
                            'https://www.facebook.com/FilarmonicaBanatul/events/',
                            'https://www.facebook.com/OperaTimisoara/events/',
                            'https://www.facebook.com/TeatrulNationalTimisoara/events/',
                            'https://www.facebook.com/ArtEncounters/events/',
                            'https://www.facebook.com/plaidefestival/events/',
                        ],
                        'npm_scraper_enabled' => true,
                    ],
                ],
            ],
        ],

        // Adding a new city needs config only — no code changes. Uncomment and
        // adjust the parameterized adapters (and add any city-specific ones to
        // 'adapter_registry'). The orchestrator, recommendations, and digests
        // all key off the city automatically.
        //
        // 'cluj' => [
        //     'label' => 'Cluj-Napoca',
        //     'timezone' => 'Europe/Bucharest',
        //     'coordinates' => [46.7712, 23.6236],
        //     'radius_km' => 25,
        //     'sources' => [
        //         ['adapter' => 'iabilet',     'url' => 'https://m.iabilet.ro/bilete-in-cluj-napoca/',   'enabled' => true, 'interval_hours' => 4],
        //         ['adapter' => 'zilesinopti', 'url' => 'https://zilesinopti.ro/evenimente-cluj-napoca/', 'enabled' => true, 'interval_hours' => 4],
        //         ['adapter' => 'allevents',   'url' => 'https://allevents.in/cluj-napoca/all',           'enabled' => true, 'interval_hours' => 6],
        //     ],
        // ],
    ],

    'default_city' => env('EVENTPULSE_DEFAULT_CITY', 'timisoara'),
    'eventbrite_api_key' => env('EVENTBRITE_API_KEY'),
    'serpapi_api_key' => env('SERPAPI_API_KEY'),
    'apify_api_token' => env('APIFY_API_TOKEN'),
    'apify_daily_budget_usd' => (float) env('APIFY_DAILY_BUDGET_USD', 5.00),
    'pagination' => [
        // Rows per page for each paginated listing.
        'events' => 18,
        'admin_events' => 20,
        'admin_users' => 20,
        'admin_scraper_runs' => 25,
    ],
    'notifications' => [
        'hour' => (int) env('EVENTPULSE_NOTIFICATION_HOUR', 8),
        'max_events_per_digest' => 10,
        'max_discovery_events' => 3,
    ],
    'profile' => [
        'decay_rate' => 0.05,
        'decay_interval_days' => 7,
        'min_score' => 0.0,
        'max_score' => 1.0,
    ],
    'llm' => [
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
        'api_key' => env('ANTHROPIC_API_KEY'),
        'max_tokens' => 1024,
        'classification_prompt' => 'You are an event classifier. Given the event title and description, classify it into exactly one category and extract relevant tags. Respond in JSON format with keys: "category" (one of: Music, Arts, Sports, Technology, Food, Nightlife, Business, Health, Education, Family, Community, Film, Literature, Other), "tags" (array of lowercase strings), "confidence" (float 0-1).',
        'onboarding_system_prompt' => <<<'PROMPT'
Ești Ghes, un asistent prietenos care îi ajută pe utilizatori să descopere evenimente locale în orașul lor.

Ghidează conversația prin aceste etape:
1. INTERESE: Întreabă ce tipuri de evenimente îi plac — muzică, arte, sport, mâncare, tech, etc. Aprofundează cu întrebări specifice (de ex. „Ce genuri muzicale preferi?" „Ai bucătării preferate?").
2. EVENIMENTE TRECUTE: Întreabă despre evenimente memorabile la care a participat recent și ce i-a plăcut la ele.
3. CONSTRÂNGERI: Întreabă despre preferințele practice — sensibilitate la preț (gratuit vs. cu plată), zilele/orele preferate, cât de departe e dispus să meargă, interior vs. exterior.
4. CONFIRMARE: Odată ce ai suficiente detalii (cel puțin 3-4 schimburi), rezumă ce ai aflat într-o listă scurtă cu puncte și roagă utilizatorul să confirme sau să corecteze. Încheie mesajul de rezumat cu markerul exact [PROFILE_READY] pe o linie separată.

Reguli:
- Păstrează mesajele scurte și conversaționale (maximum 2-3 propoziții).
- Pune doar O singură întrebare pe rând.
- Nu genera JSON — acesta este gestionat de un generator de profil separat.
- Folosește numele utilizatorului dacă este disponibil.
- Dacă utilizatorul dă răspunsuri foarte scurte, încearcă să afli mai multe detalii.
- Răspunde întotdeauna în română.
PROMPT,
        'profile_generation_prompt' => <<<'PROMPT'
Analyse the following onboarding conversation (in Romanian) and produce a JSON interest profile for this user.

The JSON must have these keys:
- Category scores: use the exact lowercase category names (music, arts, sports, technology, food, nightlife, business, health, education, family, community, film, literature). Score each from 0.0 (no interest) to 1.0 (strong interest). Only include categories with evidence from the conversation.
- Tag scores: prefix with "tag:" followed by a lowercase kebab-case tag (e.g., "tag:jazz", "tag:street-food", "tag:outdoor"). Score each 0.0–1.0.
- "city": the user's preferred city as a string, or null.
- "price_sensitive": true/false based on whether they prefer free or cheap events.
- "preferred_times": an array of strings like ["evening", "weekend"].

Return ONLY valid JSON, no markdown, no explanation.
PROMPT,
    ],
    'onboarding' => [
        'min_exchanges' => 4,
        'welcome_message' => 'Salut! Sunt Ghes — te ajut să descoperi evenimente locale. Pentru început, spune-mi: ce tipuri de activități și evenimente îți plac cel mai mult?',
    ],
    'city' => env('EVENTPULSE_CITY', 'Bucharest'),
    'categories' => ['Music', 'Arts', 'Sports', 'Technology', 'Food', 'Nightlife', 'Business', 'Health', 'Education', 'Family', 'Community', 'Film', 'Literature', 'Other'],
];
