# CLAUDE.md — Ghes

## Project Overview

Ghes is a personalized local event discovery platform. It scrapes events from multiple sources, classifies them with AI, and delivers curated notifications to users based on their interest profiles. Users onboard via a chat interface and refine recommendations through feedback. A discovery engine surfaces novel events to expand user horizons.

## Tech Stack

- **Backend:** Laravel 12, PHP 8.3
- **Frontend:** React 18 + Inertia.js + shadcn/ui + Tailwind CSS v4
- **Database:** PostgreSQL 16
- **Cache / Queue broker:** Redis 7
- **Queue worker:** Laravel Horizon
- **Search:** Meilisearch (event full-text search via Laravel Scout)
- **AI/LLM:** Anthropic Claude API (event classification, onboarding chat, profile generation)
- **Email:** Laravel Mail + MJML-compiled HTML templates
- **Scraping:** Custom PHP scraper adapters + Browsershot for JS-rendered pages
- **Geocoding:** Nominatim (OSM) or Google Geocoding API
- **Testing:** Pest PHP
- **Static analysis:** PHPStan level 6, Laravel Pint for formatting
- **Deployment:** Laravel Forge on Ubuntu VPS

## Project Structure

```
eventpulse/
├── app/
│   ├── Console/Commands/           # Artisan commands (scrape, classify, notify, decay)
│   ├── Contracts/                  # Interfaces
│   │   └── ScraperAdapter.php
│   ├── DTOs/                       # Data Transfer Objects
│   │   └── RawEvent.php
│   ├── Enums/                      # PHP 8.3 enums
│   │   ├── EventCategory.php
│   │   ├── Reaction.php
│   │   ├── NotificationChannel.php
│   │   └── NotificationFrequency.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Auth/
│   │   │   ├── ChatController.php
│   │   │   ├── EventController.php
│   │   │   ├── RecommendationController.php
│   │   │   ├── FeedbackController.php
│   │   │   ├── ProfileController.php
│   │   │   ├── NotificationSettingsController.php
│   │   │   └── Admin/ScraperController.php
│   │   ├── Requests/               # Form requests with validation
│   │   └── Resources/              # API resources / Inertia props
│   ├── Jobs/
│   │   ├── RunScraperJob.php
│   │   ├── ProcessRawEventJob.php
│   │   ├── ClassifyEventJob.php
│   │   ├── GeocodeEventJob.php
│   │   ├── ComposeNotificationJob.php
│   │   ├── SendNotificationJob.php
│   │   ├── ProcessFeedbackJob.php
│   │   ├── DecayProfileScoresJob.php
│   │   └── CleanupExpiredEventsJob.php
│   ├── Models/
│   │   ├── User.php
│   │   ├── Event.php
│   │   ├── UserEventReaction.php
│   │   ├── Notification.php
│   │   ├── ChatMessage.php
│   │   ├── ScraperRun.php
│   │   └── DiscoveryLog.php
│   ├── Services/
│   │   ├── Scraping/
│   │   │   ├── ScraperOrchestrator.php
│   │   │   └── Adapters/
│   │   │       ├── AbstractHtmlScraper.php   # Base class: HTTP, Romanian date/price parsing, fingerprinting
│   │   │       ├── IaBiletScraper.php        # Parameterized (multi-city)
│   │   │       ├── ZileSiNoptiScraper.php    # Parameterized (multi-city)
│   │   │       ├── AllEventsScraper.php       # Parameterized (multi-city)
│   │   │       ├── EventbriteScraper.php      # Parameterized (multi-city, API-based)
│   │   │       ├── OnEventScraper.php         # Parameterized (multi-city)
│   │   │       ├── EntertixScraper.php        # Parameterized (multi-city, city_filter)
│   │   │       ├── MeetupScraper.php          # Parameterized (multi-city, API-based)
│   │   │       ├── GoogleEventsScraper.php    # Parameterized (multi-city, SerpApi)
│   │   │       ├── TimisoreniScraper.php      # City-specific: Timișoara
│   │   │       ├── OperaTimisoaraScraper.php  # City-specific: Timișoara
│   │   │       ├── TeatruNationalTmScraper.php# City-specific: Timișoara
│   │   │       ├── VisitTimisoaraScraper.php  # City-specific: Timișoara
│   │   │       ├── RadioTimisoaraScraper.php  # City-specific: Timișoara
│   │   │       └── GenericHtmlScraper.php     # Configurable CSS-selector scraper for quick additions
│   │   ├── Processing/
│   │   │   ├── EventDeduplicator.php
│   │   │   ├── EventClassifier.php
│   │   │   ├── EventEnricher.php
│   │   │   └── EventPipeline.php
│   │   ├── Recommendation/
│   │   │   ├── RecommendationEngine.php
│   │   │   ├── DiscoveryEngine.php
│   │   │   ├── DiversityFilter.php
│   │   │   └── FeedbackProcessor.php
│   │   ├── Chat/
│   │   │   ├── OnboardingAgent.php
│   │   │   ├── ProfileUpdateAgent.php
│   │   │   └── ProfileGenerator.php
│   │   ├── Notification/
│   │   │   ├── NotificationComposer.php
│   │   │   ├── EmailRenderer.php
│   │   │   └── NotificationDispatcher.php
│   │   └── InterestProfile/
│   │       ├── ProfileScorer.php
│   │       ├── ProfileUpdater.php
│   │       └── ProfileDecayer.php
│   └── Providers/
├── config/
│   └── eventpulse.php              # App-specific config (weights, thresholds, scraper schedules)
├── database/
│   ├── migrations/
│   └── seeders/
├── resources/
│   ├── js/
│   │   ├── Pages/
│   │   │   ├── Auth/
│   │   │   ├── Onboarding/
│   │   │   │   └── Chat.jsx         # Chat-based onboarding UI
│   │   │   ├── Dashboard/
│   │   │   │   ├── Index.jsx         # Main dashboard
│   │   │   │   ├── SavedEvents.jsx
│   │   │   │   └── Profile.jsx
│   │   │   ├── Events/
│   │   │   │   ├── Index.jsx         # Browse/search events
│   │   │   │   └── Show.jsx          # Event detail
│   │   │   └── Settings/
│   │   │       └── Notifications.jsx
│   │   ├── Components/
│   │   │   ├── Chat/
│   │   │   │   ├── ChatWindow.jsx
│   │   │   │   ├── ChatBubble.jsx
│   │   │   │   └── TypingIndicator.jsx
│   │   │   ├── Events/
│   │   │   │   ├── EventCard.jsx
│   │   │   │   ├── EventList.jsx
│   │   │   │   ├── ReactionButtons.jsx
│   │   │   │   └── CategoryBadge.jsx
│   │   │   └── ui/                   # shadcn/ui components
│   │   └── Layouts/
│   │       └── AppLayout.jsx
│   └── views/
│       └── emails/
│           └── digest.blade.php      # Email template (compiled from MJML)
├── routes/
│   ├── web.php
│   └── api.php
└── tests/
    ├── Feature/
    │   ├── Scraping/
    │   ├── Processing/
    │   ├── Recommendation/
    │   ├── Chat/
    │   └── Notification/
    └── Unit/
        ├── Services/
        └── Models/
```

## Coding Standards & Conventions

### PHP / Laravel

- **PHP 8.3 features**: Use enums, readonly properties, typed properties, named arguments, match expressions, first-class callables.
- **Strict types**: Every PHP file starts with `declare(strict_types=1);`
- **Return types**: Every method has an explicit return type.
- **Formatting**: Laravel Pint with default `laravel` preset. Run `./vendor/bin/pint` before commits.
- **Static analysis**: PHPStan level 6. Run `./vendor/bin/phpstan analyse` before commits.
- **DTOs over arrays**: Use typed DTOs (readonly classes) for data transfer between services. Never pass untyped arrays between service boundaries.
- **Service pattern**: Business logic lives in `App\Services\`, not in controllers or models. Controllers are thin (validate → delegate → respond).
- **Actions for single-purpose operations**: If a service method does one thing and is called from one place, consider an Action class instead.
- **Enums for constants**: Use PHP enums for all categorical constants (categories, reactions, notification channels, etc.). Never use string constants.
- **Config over hardcoding**: All tunable values (weights, thresholds, cadences, API keys) go in `config/eventpulse.php` and are read via `config('eventpulse.xxx')`.
- **Jobs for async work**: Any operation that takes > 200ms or calls an external API goes through the queue. Use specific queue names: `scraping`, `processing`, `ai`, `enrichment`, `notifications`, `default`.
- **Eloquent conventions**: Use UUIDs as primary keys. Use `$casts` for JSON columns, date columns, and enums. Define relationships explicitly.
- **Database**: PostgreSQL-specific features are welcome (JSONB operators, GIN indexes, `gen_random_uuid()`). All queries should use the query builder or Eloquent — no raw SQL except in migrations.
- **Error handling**: Wrap external API calls (Claude, geocoding, scrapers) in try/catch. Log failures and dispatch to a dead-letter queue for retry. Never let a scraper failure crash the pipeline.

### Frontend (React + Inertia)

- **Functional components only** with hooks.
- **shadcn/ui** for all base components. Never build custom buttons, inputs, modals, etc. from scratch.
- **Tailwind v4** utility classes. No custom CSS files unless absolutely necessary.
- **Inertia conventions**: Use `useForm()` for forms, `usePage()` for shared data, `router.visit()` for navigation.
- **TypeScript-like discipline**: Even though we use JSX, prop-type all components with JSDoc or default props.
- **File naming**: PascalCase for components (`EventCard.jsx`), camelCase for utilities (`formatDate.js`).
- **State management**: Local state via `useState`/`useReducer`. No global state library — Inertia's shared data and page props are the source of truth.

### Testing

- **Framework**: Pest PHP exclusively. No PHPUnit syntax.
- **Coverage targets**: 80%+ on services, 60%+ overall.
- **Naming**: `it('scores music events higher for users who like music')` — descriptive, behavior-focused.
- **Structure**: Mirror source directory in tests. `tests/Unit/Services/Recommendation/RecommendationEngineTest.php` for `App\Services\Recommendation\RecommendationEngine`.
- **Factories**: Use model factories for all test data. Never hardcode IDs.
- **External services**: Mock all external API calls (Claude, geocoding, scrapers). Use `Http::fake()` for HTTP calls, dedicated fakes for service classes.
- **Database**: Use `RefreshDatabase` trait. Tests run against a test PostgreSQL database.

### LLM Integration (Claude API)

- **Client**: Use the Anthropic PHP SDK or a simple HTTP wrapper service (`App\Services\Anthropic\ClaudeClient`).
- **Prompts**: Store all prompt templates in `config/eventpulse.php` under a `prompts` key, or in dedicated prompt classes.
- **Structured output**: Always instruct Claude to respond in JSON. Parse with `json_decode()` and validate against expected schema.
- **Rate limiting**: Queue all LLM calls on the `ai` queue. Use Laravel's rate limiter to stay within API limits.
- **Cost tracking**: Log token usage per classification/chat call. Store in a `llm_usage_log` table for monitoring.
- **Fallbacks**: If classification fails, store event with `category = "Other"` and flag for retry.

### Git Conventions

- **Branching**: `main` (production), `develop` (staging), `feature/*`, `fix/*`, `chore/*`.
- **Commits**: Conventional commits: `feat:`, `fix:`, `refactor:`, `test:`, `chore:`, `docs:`.
- **PRs**: Every feature branch gets a PR. CI must pass (Pint, PHPStan, Pest) before merge.

## Key Architecture Decisions

1. **PostgreSQL over MySQL**: JSONB support for flexible interest profiles, tags, and metadata. GIN indexes for tag queries. `gen_random_uuid()` for UUIDs.
2. **Separate queue names**: Prevents scraper backlog from blocking notification delivery. Horizon can allocate different worker counts per queue.
3. **LLM for classification over rule-based**: Event descriptions are too varied for regex/keyword matching. LLM classification handles edge cases and multi-language content gracefully.
4. **Chat-based onboarding over form-based**: Conversational UI captures nuance ("I like jazz but not smooth jazz") that checkboxes can't. The LLM extracts structured preferences from natural language.
5. **Email-first notifications**: Lowest friction for MVP. Push notifications come in Phase 2.
6. **Scraper adapter pattern**: Each source is a pluggable adapter implementing `ScraperAdapter`. Adding a new source means writing one class — no changes to the pipeline.
7. **Discovery as first-class feature**: The exploration budget is baked into `NotificationComposer` from day one, not bolted on later.
8. **Multi-city config with parameterized scrapers**: Scraper *classes* are reusable across cities (iaBilet, Zile și Nopți, etc. — only the URL/params change). Config is organized *per-city*, where each city lists its sources with adapter key + URL/params. City-specific scrapers (e.g., OperaTimisoaraScraper) only appear in that city's config. This means adding a new city requires zero code for shared scrapers — just config lines. See `config/eventpulse.php` under `cities`.

## Environment Variables

```
APP_NAME=EventPulse
APP_ENV=local
APP_URL=http://eventpulse.test

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=eventpulse
DB_USERNAME=eventpulse
DB_PASSWORD=

REDIS_HOST=127.0.0.1

QUEUE_CONNECTION=redis

ANTHROPIC_API_KEY=sk-ant-...
ANTHROPIC_MODEL=claude-sonnet-4-20250514

MEILISEARCH_HOST=http://127.0.0.1:7700
MEILISEARCH_KEY=

GEOCODING_PROVIDER=nominatim  # or "google"
GOOGLE_GEOCODING_KEY=

MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=events@eventpulse.app
MAIL_FROM_NAME="EventPulse"

EVENTPULSE_DEFAULT_CITY=timisoara
EVENTPULSE_NOTIFICATION_HOUR=8
EVENTPULSE_DEDUP_ENABLED=true

EVENTBRITE_API_KEY=
SERPAPI_API_KEY=
```

## Common Commands

```bash
# Development
php artisan serve
npm run dev

# Queue workers
php artisan horizon

# Run scrapers manually
php artisan eventpulse:scrape                              # all sources, default city
php artisan eventpulse:scrape --city=timisoara              # all sources for a city
php artisan eventpulse:scrape --city=timisoara --source=iabilet  # single source, single city

# Process pending raw events
php artisan eventpulse:process-events

# Backfill identity keys and merge existing duplicates
php artisan eventpulse:dedupe-events --dry-run          # preview inside a rolled-back transaction
php artisan eventpulse:dedupe-events --backfill-only    # write identity keys only, merge nothing
php artisan eventpulse:dedupe-events --fuzzy            # also merge on the scored fallback

# Send notifications
php artisan eventpulse:send-notifications        # to all due users
php artisan eventpulse:send-notifications --user=UUID  # to one user

# Profile decay (normally scheduled, but can run manually)
php artisan eventpulse:decay-profiles

# Code quality
./vendor/bin/pint
./vendor/bin/phpstan analyse
./vendor/bin/pest
./vendor/bin/pest --coverage
```

## Implementation Priority

Build in this order:

1. **Database schema** — migrations for all tables
2. **Models + factories** — all Eloquent models with casts, relationships, factories
3. **Enums** — EventCategory, Reaction, NotificationChannel, NotificationFrequency
4. **Config** — `config/eventpulse.php` with all tunable values
5. **Scraper infrastructure** — ScraperAdapter interface, AbstractHtmlScraper base class, ScraperOrchestrator with per-city config, MVP adapters (IaBilet, ZileSiNopti, AllEvents, Eventbrite)
6. **Event pipeline** — EventDeduplicator → EventClassifier → EventEnricher → EventPipeline
7. **Interest profile services** — ProfileScorer, ProfileUpdater, ProfileDecayer
8. **Recommendation engine** — RecommendationEngine, DiscoveryEngine, DiversityFilter
9. **Chat / onboarding** — OnboardingAgent, ProfileGenerator, ChatController
10. **Notifications** — NotificationComposer, EmailRenderer, NotificationDispatcher
11. **API + controllers** — all routes and controllers
12. **Frontend pages** — Onboarding chat → Dashboard → Event browse → Settings
13. **Artisan commands** — CLI wrappers for all scheduled operations
14. **Scheduled tasks** — `app/Console/Kernel.php` scheduling
15. **Tests** — unit tests for services, feature tests for API endpoints

## Things to Watch Out For

- **Scraper fragility**: HTML scrapers break when sites change. Log errors per source and alert on >3 consecutive failures. Build scrapers defensively with null-safe extraction.
- **LLM response validation**: Claude's JSON output can occasionally be malformed. Always validate parsed output against expected keys/types. Fall back gracefully.
- **Profile score boundaries**: Always clamp scores to [0.0, 1.0] after any update. Use `max(0.0, min(1.0, $score))` everywhere.
- **Email reaction tracking**: Reaction links in emails need signed tokens (Laravel's `URL::signedRoute()`) to prevent spoofing.
- **Event expiry**: Events in the past should be soft-excluded from recommendations but kept in DB for analytics. Add a scope: `Event::upcoming()`.
- **Rate limits**: Claude API, geocoding APIs, and scraped sites all have rate limits. Use Laravel's `RateLimiter` and queue throttling.
- **Timezone handling**: Store all times in UTC. Convert to user's local timezone only in presentation layer (email, dashboard).

## Scraper Config Architecture

Scrapers are organized per-city in `config/eventpulse.php`. Scraper classes are either **parameterized** (reusable across cities — receive their URL/params from config) or **city-specific** (unique adapter for one city). The `ScraperOrchestrator` reads the active city's source list and dispatches jobs accordingly.

**Scraper adapter contract:** Every adapter's `scrape()` method receives a source config array (containing `url`, `params`, `city_filter`, etc.) instead of hardcoded URLs. The adapter uses this config to know what to fetch.

**Config structure in `config/eventpulse.php`:**

```php
'default_city' => env('EVENTPULSE_DEFAULT_CITY', 'timisoara'),

'adapter_registry' => [
    // Maps adapter key → class. Parameterized scrapers are reused across cities.
    'iabilet'          => \App\Services\Scraping\Adapters\IaBiletScraper::class,
    'zilesinopti'      => \App\Services\Scraping\Adapters\ZileSiNoptiScraper::class,
    'allevents'        => \App\Services\Scraping\Adapters\AllEventsScraper::class,
    'eventbrite'       => \App\Services\Scraping\Adapters\EventbriteScraper::class,
    'onevent'          => \App\Services\Scraping\Adapters\OnEventScraper::class,
    'entertix'         => \App\Services\Scraping\Adapters\EntertixScraper::class,
    'meetup'           => \App\Services\Scraping\Adapters\MeetupScraper::class,
    'google_events'    => \App\Services\Scraping\Adapters\GoogleEventsScraper::class,
    // City-specific (Timișoara)
    'timisoreni'       => \App\Services\Scraping\Adapters\TimisoreniScraper::class,
    'opera_timisoara'  => \App\Services\Scraping\Adapters\OperaTimisoaraScraper::class,
    'teatru_national_tm' => \App\Services\Scraping\Adapters\TeatruNationalTmScraper::class,
    'visit_timisoara'  => \App\Services\Scraping\Adapters\VisitTimisoaraScraper::class,
    'radio_timisoara'  => \App\Services\Scraping\Adapters\RadioTimisoaraScraper::class,
],

'cities' => [
    'timisoara' => [
        'label'       => 'Timișoara',
        'coordinates' => [45.7489, 21.2087],
        'radius_km'   => 25,
        'timezone'    => 'Europe/Bucharest',
        'sources'     => [
            // Parameterized scrapers — same class, city-specific URL/params
            ['adapter' => 'iabilet',       'url' => 'https://m.iabilet.ro/bilete-in-timisoara/',        'enabled' => true,  'interval_hours' => 4],
            ['adapter' => 'zilesinopti',   'url' => 'https://zilesinopti.ro/evenimente-timisoara/',      'enabled' => true,  'interval_hours' => 4,
             'extra_urls' => ['https://zilesinopti.ro/evenimente-timisoara-weekend/']],
            ['adapter' => 'allevents',     'url' => 'https://allevents.in/timisoara/all',               'enabled' => true,  'interval_hours' => 6],
            ['adapter' => 'eventbrite',    'params' => ['address' => 'Timisoara,Romania'],               'enabled' => true,  'interval_hours' => 6],
            ['adapter' => 'onevent',       'url' => 'https://www.onevent.ro/orase/timisoara/',           'enabled' => false, 'interval_hours' => 6],
            ['adapter' => 'entertix',      'url' => 'https://www.entertix.ro/evenimente',
             'city_filter' => 'Timișoara',                                                               'enabled' => false, 'interval_hours' => 8],
            ['adapter' => 'meetup',        'params' => ['location' => 'ro--timisoara'],                  'enabled' => false, 'interval_hours' => 6],
            ['adapter' => 'google_events', 'params' => ['q' => 'Events in Timisoara'],                   'enabled' => false, 'interval_hours' => 12],

            // City-specific scrapers — unique to Timișoara
            ['adapter' => 'timisoreni',         'url' => 'https://www.timisoreni.ro/info/index/t--evenimente/', 'enabled' => false, 'interval_hours' => 8],
            ['adapter' => 'opera_timisoara',    'url' => 'https://www.ort.ro/ro/Spectacole.html',               'enabled' => false, 'interval_hours' => 24],
            ['adapter' => 'teatru_national_tm', 'url' => 'https://www.tntm.ro/',                                'enabled' => false, 'interval_hours' => 24],
            ['adapter' => 'visit_timisoara',    'url' => 'https://visit-timisoara.com/events-activities/',       'enabled' => false, 'interval_hours' => 12],
            ['adapter' => 'radio_timisoara',    'url' => 'https://www.radiotimisoara.ro/agenda-evenimente',      'enabled' => false, 'interval_hours' => 12],
        ],
    ],
    // Future cities:
    // 'cluj' => [
    //     'label' => 'Cluj-Napoca',
    //     'coordinates' => [46.7712, 23.6236],
    //     'radius_km' => 25,
    //     'timezone' => 'Europe/Bucharest',
    //     'sources' => [
    //         ['adapter' => 'iabilet',     'url' => 'https://m.iabilet.ro/bilete-in-cluj-napoca/',     'enabled' => true, 'interval_hours' => 4],
    //         ['adapter' => 'zilesinopti', 'url' => 'https://zilesinopti.ro/evenimente-cluj-napoca/',   'enabled' => true, 'interval_hours' => 4],
    //         ['adapter' => 'allevents',   'url' => 'https://allevents.in/cluj-napoca/all',             'enabled' => true, 'interval_hours' => 6],
    //         // Cluj-specific scrapers would go here
    //     ],
    // ],
],
```

**How ScraperOrchestrator uses this:**

```php
// Run all enabled sources for a city
$orchestrator->runCity('timisoara');

// Run one source for a city
$orchestrator->runSource('timisoara', 'iabilet');

// Run all enabled sources for all cities
$orchestrator->runAll();
```

**How adapters receive config:** The `ScraperAdapter` interface's `scrape()` method signature is:

```php
public function scrape(array $sourceConfig, array $cityConfig): Collection;
```

Where `$sourceConfig` is one entry from the city's `sources` array and `$cityConfig` is the city-level config (label, coordinates, timezone). This way the adapter gets the URL, params, and city context without hardcoding anything.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- inertiajs/inertia-laravel (INERTIA_LARAVEL) - v3
- laravel/framework (LARAVEL) - v13
- laravel/horizon (HORIZON) - v5
- laravel/prompts (PROMPTS) - v0
- laravel/scout (SCOUT) - v11
- larastan/larastan (LARASTAN) - v3
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- @inertiajs/react (INERTIA_REACT) - v3
- react (REACT) - v19
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

- `laravel-best-practices` — Apply this skill whenever writing, reviewing, or refactoring Laravel PHP code. This includes creating or modifying controllers, models, migrations, form requests, policies, jobs, scheduled commands, service classes, and Eloquent queries. Triggers for N+1 and query performance issues, caching strategies, authorization and security patterns, validation, error handling, queue and job configuration, route definitions, and architectural decisions. Also use for Laravel code reviews and refactoring existing Laravel code to follow best practices. Covers any task involving Laravel backend PHP code patterns.
- `configuring-horizon` — Use this skill whenever the user mentions Horizon by name in a Laravel context. Covers the full Horizon lifecycle: installing Horizon (horizon:install, Sail setup), configuring config/horizon.php (supervisor blocks, queue assignments, balancing strategies, minProcesses/maxProcesses), fixing the dashboard (authorization via Gate::define viewHorizon, blank metrics, horizon:snapshot scheduling), and troubleshooting production issues (worker crashes, timeout chain ordering, LongWaitDetected notifications, waits config). Also covers job tagging and silencing. Do not use for generic Laravel queues without Horizon, SQS or database drivers, standalone Redis setup, Linux supervisord, Telescope, or job batching.
- `scout-development` — Develops full-text search with Laravel Scout. Activates when installing or configuring Scout; choosing a search engine (Algolia, Meilisearch, Typesense, Database, Collection); adding the Searchable trait to models; customizing toSearchableArray or searchableAs; importing or flushing search indexes; writing search queries with where clauses, pagination, or soft deletes; configuring index settings; troubleshooting search results; or when the user mentions Scout, full-text search, search indexing, or search engines in a Laravel project. Make sure to use this skill whenever the user works with search functionality in Laravel, even if they don't explicitly mention Scout.
- `pest-testing` — Use this skill for Pest PHP testing in Laravel projects only. Trigger whenever any test is being written, edited, fixed, or refactored — including fixing tests that broke after a code change, adding assertions, converting PHPUnit to Pest, adding datasets, and TDD workflows. Always activate when the user asks how to write something in Pest, mentions test files or directories (tests/Feature, tests/Unit, tests/Browser), or needs browser testing, smoke testing multiple pages for JS errors, or architecture tests. Covers: it()/expect() syntax, datasets, mocking, browser testing (visit/click/fill), smoke testing, arch(), Livewire component tests, RefreshDatabase, and all Pest 4 features. Do not use for factories, seeders, migrations, controllers, models, or non-test PHP code.
- `tailwindcss-development` — Always invoke when the user's message includes 'tailwind' in any form. Also invoke for: building responsive grid layouts (multi-column card grids, product grids), flex/grid page structures (dashboards with sidebars, fixed topbars, mobile-toggle navs), styling UI components (cards, tables, navbars, pricing sections, forms, inputs, badges), adding dark mode variants, fixing spacing or typography, and Tailwind v3/v4 work. The core use case: writing or fixing Tailwind utility classes in HTML templates (Blade, JSX, Vue). Skip for backend PHP logic, database queries, API routes, JavaScript with no HTML/CSS component, CSS file audits, build tool configuration, and vanilla CSS.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.
- To check environment variables, read the `.env` file directly.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/Pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-react-development` when working with Inertia client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

=== inertia-react/core rules ===

# Inertia + React

- IMPORTANT: Activate `inertia-react-development` when working with Inertia React client-side patterns.

</laravel-boost-guidelines>
