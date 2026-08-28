# EventPulse — Product & Technical Specification

## 1. Vision

**EventPulse** is a personalized local event discovery platform. It continuously scrapes event data from multiple sources across a city, classifies events by category, and delivers tailored notifications to users based on their stated interests and behavioral feedback. A TikTok-style "discovery" mechanism periodically surfaces novel event types to help users expand their horizons.

---

## 2. User Journey

### 2.1 Registration & Onboarding

1. User signs up (email + password, or OAuth via Google).
2. User enters a **chat-based onboarding flow**:
   - A conversational AI asks about interests, preferred event types, neighborhoods, budget range, days/times of availability, and how often they want to be notified.
   - The AI generates an **interest profile** (structured JSON) from the free-text conversation.
   - User confirms or tweaks the generated profile before saving.
3. User selects notification channel: **email digest** (daily/weekly), **push notification**, or **both**.

### 2.2 Steady-State Experience

- User receives curated event recommendations via their chosen channel.
- Each notification contains 5–8 events: ~70% strong matches, ~20% moderate matches, ~10% discovery/exploration events.
- User can **react** to each event: 👍 interested, 👎 not interested, 🔖 saved, ❌ hide events like this.
- Reactions feed back into the recommendation engine.

### 2.3 Web Dashboard (Lightweight)

- View upcoming saved events.
- Browse the latest event feed with filters.
- Update interest profile via chat or manual toggles.
- View history of past recommendations and reactions.

---

## 3. Core Features

### 3.1 Event Ingestion Pipeline

**Goal:** Continuously discover and ingest events from multiple sources.

**Sources (initial set, extensible):**

| Source Type        | Examples                                     | Method            |
|--------------------|----------------------------------------------|-------------------|
| Ticketing platforms| Eventbrite, Meetup, Facebook Events           | API + scraping    |
| City/municipal     | City hall calendars, tourism board sites      | HTML scraping     |
| Venue websites     | Theaters, galleries, clubs, coworking spaces  | HTML scraping     |
| Social media       | Facebook event pages, Instagram event posts   | API + scraping    |
| Aggregators        | AllEvents.in, local "what's on" blogs         | RSS + scraping    |

**Pipeline stages:**

```
[Scrapers/APIs] → [Raw Event Queue] → [Deduplication] → [Classification] → [Enrichment] → [Event Store]
```

1. **Scraper orchestrator** — Schedules and runs scrapers on a configurable cadence (e.g., every 4h). Each source has its own scraper adapter implementing a common interface.
2. **Raw Event Queue** — Redis Streams or a simple DB staging table. Each raw event includes: title, description, date/time, location (text), source URL, raw HTML snippet.
3. **Deduplication** — Matching on a blocking key of `city-slug | local calendar date | sorted title-token key`, with a scored fallback (weighted title / venue / time, gated by an independent title-similarity floor) over same-city candidates within a day. Dates are compared as the **local calendar date in the city's timezone**, because sources disagree on the time of day by hours and some publish a bare date. A match enriches one canonical event rather than discarding the later copy, and every provider that reported it is recorded in `event_sources`.
4. **Classification** — LLM-based (Claude API) classification into a taxonomy:
   - **Primary categories:** Music, Art & Culture, Sports & Fitness, Food & Drink, Tech & Science, Business & Networking, Community & Social, Family & Kids, Outdoors & Adventure, Nightlife, Theater & Film, Workshops & Classes, Festivals, Other.
   - **Tags:** Free-form tags extracted by the LLM (e.g., "jazz", "vegan", "startup", "running", "photography").
   - **Metadata extraction:** Structured fields (price range, age restriction, indoor/outdoor, formality level) parsed by the LLM from unstructured text.
5. **Enrichment** — Geocode the venue address (Google Maps / Nominatim). Attach neighborhood/district labels. Fetch venue photos if available.
6. **Event Store** — PostgreSQL. Final structured event records.

### 3.2 User Interest Model

The interest profile is a structured representation of user preferences:

```json
{
  "user_id": "uuid",
  "categories": {
    "Music": 0.9,
    "Tech & Science": 0.8,
    "Food & Drink": 0.7,
    "Nightlife": 0.3,
    "Sports & Fitness": 0.1
  },
  "tags": {
    "jazz": 0.95,
    "startup": 0.85,
    "craft beer": 0.7,
    "yoga": 0.4
  },
  "constraints": {
    "neighborhoods": ["Old Town", "University District"],
    "max_price": 30,
    "preferred_days": ["friday", "saturday", "sunday"],
    "preferred_times": ["evening", "afternoon"]
  },
  "discovery_openness": 0.15,
  "notification_frequency": "daily"
}
```

**Score semantics:** 0.0 = no interest → 1.0 = maximum interest. Scores decay slowly over time and are boosted/penalized by feedback.

### 3.3 Recommendation Engine

**Scoring formula per event:**

```
score = (
    w_cat  * category_match(event, user)
  + w_tag  * tag_match(event, user)
  + w_loc  * location_match(event, user)
  + w_time * time_match(event, user)
  + w_price * price_match(event, user)
  + w_fresh * freshness_bonus(event)
  + w_pop  * popularity_signal(event)
) * diversity_modifier
```

Default weights: `w_cat=0.30, w_tag=0.25, w_loc=0.15, w_time=0.10, w_price=0.05, w_fresh=0.05, w_pop=0.10`

**Diversity modifier:** Reduces score of events too similar to already-selected events in the same batch, ensuring variety.

### 3.4 Discovery / Exploration Engine (TikTok-style)

**Purpose:** Surface events outside the user's known preferences to test latent interests.

**Mechanism:**

1. **Exploration budget:** Each notification batch reserves ~10% of slots for discovery events.
2. **Category exploration:** Pick a category the user has NOT interacted with, or has a low score for. Weight selection toward categories that are popular among similar users (collaborative filtering).
3. **Trending injection:** If an event is generating high engagement across the platform, boost it into discovery slots regardless of user profile.
4. **Feedback loop:**
   - If user reacts positively to a discovery event → increase that category/tag score significantly (exploration reward).
   - If user reacts negatively → small penalty, but don't suppress the category entirely (allow retry later).
   - Track discovery hit rate per user; if a user consistently ignores discovery events, reduce `discovery_openness` slightly.
5. **Serendipity decay:** A discovery category that gets surfaced 3+ times with no positive reaction gets deprioritized for 30 days.

### 3.5 Feedback System

**Feedback types:**

| Action              | Effect on model                                                    |
|---------------------|---------------------------------------------------------------------|
| 👍 Interested        | +0.15 to matching categories, +0.20 to matching tags               |
| 👎 Not interested    | -0.10 to matching categories, -0.15 to matching tags               |
| 🔖 Saved             | +0.10 to matching categories, +0.15 to matching tags               |
| ❌ Hide like this    | -0.25 to matching categories, -0.30 to matching tags, add neg tag  |
| Opened link         | +0.05 implicit signal                                              |
| Ignored (no action) | -0.02 passive decay to matching categories                         |

Score changes are clamped to [0.0, 1.0]. Negative tags are stored separately and used as filters.

### 3.6 Chat Interface (Onboarding + Profile Updates)

**Implementation:** A conversational UI powered by Claude API.

**Onboarding prompt structure:**
1. Ask about general interests and hobbies.
2. Ask about event types they've enjoyed in the past.
3. Ask about practical constraints (location, budget, schedule).
4. Summarize the interest profile and ask for confirmation.
5. Generate the structured JSON profile.

**Ongoing updates:** User can open the chat anytime to say things like:
- "I'm getting into pottery lately"
- "Stop sending me networking events"
- "I moved to the Riverside neighborhood"

The chat agent updates the profile accordingly.

### 3.7 Notification System

**Email digest:**
- Rendered via MJML templates → HTML email.
- Contains: event image, title, date, venue, category badge, 1-line description, source link.
- Reaction buttons (👍👎🔖❌) as links with tracking tokens.
- Footer: "Update preferences" link, unsubscribe.

**Push notifications (future):**
- Web push via service worker.
- Brief: "3 new events matching your interests this weekend"

---

## 4. Technical Architecture

### 4.1 Stack

| Layer               | Technology                                       |
|---------------------|--------------------------------------------------|
| Backend framework   | Laravel 12 (PHP 8.3)                              |
| Frontend (dashboard)| React 18 + Inertia.js + shadcn/ui + Tailwind v4  |
| Database            | PostgreSQL 16                                     |
| Cache / Queue broker| Redis 7                                           |
| Queue worker        | Laravel Horizon                                   |
| Search              | Meilisearch (event full-text search)              |
| LLM                 | Claude API (classification, chat, profile gen)    |
| Email               | Laravel Mail + MJML templates                     |
| Scraping            | Custom PHP scrapers + Browsershot for JS-rendered  |
| Geocoding           | Nominatim (self-hosted) or Google Geocoding API   |
| Scheduler           | Laravel Task Scheduling (cron)                    |
| Deployment          | Laravel Forge on Ubuntu VPS                       |

### 4.2 Database Schema (Core Tables)

```sql
-- Events
CREATE TABLE events (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    title VARCHAR(500) NOT NULL,
    description TEXT,
    starts_at TIMESTAMPTZ NOT NULL,
    ends_at TIMESTAMPTZ,
    venue_name VARCHAR(300),
    venue_address TEXT,
    latitude DECIMAL(10, 7),
    longitude DECIMAL(10, 7),
    neighborhood VARCHAR(100),
    city VARCHAR(100) NOT NULL,
    price_min DECIMAL(8, 2),
    price_max DECIMAL(8, 2),
    is_free BOOLEAN DEFAULT false,
    image_url TEXT,
    source_url TEXT NOT NULL,
    source_name VARCHAR(100) NOT NULL,
    primary_category VARCHAR(50) NOT NULL,
    tags JSONB DEFAULT '[]',
    metadata JSONB DEFAULT '{}',  -- age_restriction, formality, indoor/outdoor, etc.
    popularity_score DECIMAL(5, 2) DEFAULT 0,
    fingerprint VARCHAR(64),          -- legacy; superseded by match_key + event_sources
    -- Canonical-event identity
    match_key VARCHAR(191),           -- "{city-slug}|{local-date}|{title-token-key}"; indexed, NOT unique
    city_slug VARCHAR(255),
    local_date DATE,                  -- starts_at as a calendar date in the city's timezone
    merged_into_id UUID REFERENCES events(id) ON DELETE SET NULL,
    sources_count INT DEFAULT 1,
    last_seen_at TIMESTAMPTZ,
    ingested_at TIMESTAMPTZ DEFAULT NOW(),
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);
-- NOTE: fingerprint and source_url are deliberately NOT unique. Identity lives
-- in event_sources; a unique on either blocks both the merge model and any
-- refresh of an already-imported event.

-- One row per provider that reported an event. The unique key is what makes
-- re-scraping idempotent; occurrence_key is NOT NULL so NULL-distinctness can
-- never weaken the constraint.
CREATE TABLE event_sources (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    event_id UUID NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    source VARCHAR(100) NOT NULL,
    source_url TEXT NOT NULL,
    url_key VARCHAR(255) NOT NULL,    -- canonicalised source_url: no www./m., query or trailing slash
    source_id VARCHAR(255),
    occurrence_key VARCHAR(10) NOT NULL,  -- local 'Y-m-d', or 'undated'
    title VARCHAR(255),
    starts_at TIMESTAMPTZ,
    payload JSONB DEFAULT '{}',
    first_seen_at TIMESTAMPTZ NOT NULL,   -- set once, at creation
    last_seen_at TIMESTAMPTZ NOT NULL,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE(source, url_key, occurrence_key),
    UNIQUE(source, source_id, occurrence_key)
);

CREATE INDEX idx_events_starts_at ON events(starts_at);
CREATE INDEX idx_events_city_starts ON events(city, starts_at);
CREATE INDEX idx_events_category ON events(primary_category);
CREATE INDEX idx_events_tags ON events USING GIN(tags);
CREATE INDEX idx_events_fingerprint ON events(fingerprint);
CREATE INDEX idx_events_match_key ON events(match_key);
CREATE INDEX idx_events_city_local_date ON events(city_slug, local_date);

-- Users
CREATE TABLE users (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(200) NOT NULL,
    email VARCHAR(300) NOT NULL UNIQUE,
    password VARCHAR(255),
    city VARCHAR(100) NOT NULL,
    interest_profile JSONB NOT NULL DEFAULT '{}',
    discovery_openness DECIMAL(3, 2) DEFAULT 0.15,
    notification_channel VARCHAR(20) DEFAULT 'email',  -- email, push, both
    notification_frequency VARCHAR(20) DEFAULT 'daily', -- daily, weekly, realtime
    onboarding_completed BOOLEAN DEFAULT false,
    email_verified_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- User event interactions (feedback)
CREATE TABLE user_event_reactions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    event_id UUID NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    reaction VARCHAR(20) NOT NULL, -- interested, not_interested, saved, hidden, link_opened
    is_discovery BOOLEAN DEFAULT false,  -- was this a discovery slot event?
    notification_id UUID,  -- which notification batch included this
    created_at TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE(user_id, event_id, reaction)
);

CREATE INDEX idx_reactions_user ON user_event_reactions(user_id, created_at DESC);

-- Notification batches (audit trail)
CREATE TABLE notifications (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    channel VARCHAR(20) NOT NULL,
    event_ids JSONB NOT NULL,  -- ordered list of event UUIDs sent
    discovery_event_ids JSONB DEFAULT '[]',
    sent_at TIMESTAMPTZ DEFAULT NOW(),
    opened_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Chat messages (onboarding + profile update conversations)
CREATE TABLE chat_messages (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role VARCHAR(20) NOT NULL,  -- user, assistant, system
    content TEXT NOT NULL,
    profile_changes JSONB,  -- if this message triggered a profile update
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Scraper runs (observability)
CREATE TABLE scraper_runs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    source_name VARCHAR(100) NOT NULL,
    started_at TIMESTAMPTZ NOT NULL,
    finished_at TIMESTAMPTZ,
    events_found INT DEFAULT 0,
    events_new INT DEFAULT 0,
    events_duplicate INT DEFAULT 0,
    errors JSONB DEFAULT '[]',
    status VARCHAR(20) DEFAULT 'running' -- running, completed, failed
);

-- Discovery tracking
CREATE TABLE discovery_log (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    category VARCHAR(50) NOT NULL,
    surfaced_count INT DEFAULT 0,
    positive_reactions INT DEFAULT 0,
    last_surfaced_at TIMESTAMPTZ,
    suppressed_until TIMESTAMPTZ,  -- null = active, date = suppressed until then
    created_at TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE(user_id, category)
);
```

### 4.3 Key Services / Classes

```
App\Services\
├── Scraping\
│   ├── ScraperOrchestrator      -- schedules and dispatches scraper jobs
│   ├── Contracts\ScraperAdapter -- interface: scrape(): RawEvent[]
│   ├── Adapters\
│   │   ├── EventbriteScraper
│   │   ├── MeetupScraper
│   │   ├── FacebookEventScraper
│   │   ├── GenericHtmlScraper   -- configurable CSS selector-based scraper
│   │   └── RssFeedScraper
│   └── RawEvent                 -- DTO for unprocessed event data
│
├── Processing\
│   ├── EventDeduplicator        -- fingerprint + fuzzy match
│   ├── EventClassifier          -- Claude API: classify, extract tags/metadata
│   ├── EventEnricher            -- geocode, neighborhood, photos
│   └── EventPipeline            -- orchestrates dedup → classify → enrich → store
│
├── Recommendation\
│   ├── RecommendationEngine     -- scores events for a user
│   ├── DiscoveryEngine          -- selects exploration events
│   ├── DiversityFilter          -- ensures batch variety
│   └── FeedbackProcessor        -- applies reactions to interest profile
│
├── Chat\
│   ├── OnboardingAgent          -- Claude-powered onboarding conversation
│   ├── ProfileUpdateAgent       -- Claude-powered profile modification
│   └── ProfileGenerator         -- converts chat output to structured JSON
│
├── Notification\
│   ├── NotificationComposer     -- selects events, builds batch
│   ├── EmailRenderer            -- MJML → HTML
│   └── NotificationDispatcher   -- sends via configured channel
│
└── InterestProfile\
    ├── ProfileScorer            -- computes match scores
    ├── ProfileUpdater           -- applies feedback deltas
    └── ProfileDecayer           -- time-based score decay job
```

### 4.4 Queue Jobs

| Job                        | Queue    | Cadence / Trigger                      |
|----------------------------|----------|----------------------------------------|
| `RunScraperJob`            | scraping | Every 4 hours per source               |
| `ProcessRawEventJob`       | processing| On raw event ingestion                |
| `ClassifyEventJob`         | ai       | On new event (rate-limited)            |
| `GeocodeEventJob`          | enrichment| After classification                  |
| `ComposeNotificationJob`   | notifications| Daily at 8:00 AM / Weekly on Monday |
| `SendNotificationJob`      | notifications| After composition                    |
| `ProcessFeedbackJob`       | default  | On user reaction                       |
| `DecayProfileScoresJob`    | default  | Daily at 3:00 AM                       |
| `CleanupExpiredEventsJob`  | default  | Daily at 4:00 AM                       |

### 4.5 API Endpoints

```
Auth
  POST   /api/auth/register
  POST   /api/auth/login
  POST   /api/auth/logout

Onboarding Chat
  POST   /api/chat/message          -- send message, get AI response
  GET    /api/chat/history           -- get conversation history
  POST   /api/chat/confirm-profile   -- finalize onboarding profile

Events
  GET    /api/events                 -- browse/search events (paginated, filterable)
  GET    /api/events/{id}            -- single event detail

Recommendations
  GET    /api/recommendations        -- get current recommendation batch
  GET    /api/recommendations/history -- past batches

Feedback
  POST   /api/events/{id}/react      -- { reaction: "interested" | "not_interested" | "saved" | "hidden" }
  GET    /api/events/saved           -- user's saved events

Profile
  GET    /api/profile                -- get interest profile
  PUT    /api/profile                -- manual profile update
  GET    /api/profile/stats          -- feedback stats, discovery hit rate

Notifications
  GET    /api/notifications          -- notification history
  PUT    /api/notifications/settings -- update channel, frequency

Admin (protected)
  GET    /api/admin/scrapers         -- scraper run history
  POST   /api/admin/scrapers/{source}/run  -- trigger manual scrape
  GET    /api/admin/events/stats     -- ingestion stats
```

### 4.6 Scraper Adapter Interface

```php
interface ScraperAdapter
{
    public function source(): string;  // e.g., "eventbrite"

    public function scrape(string $city, array $options = []): Collection;  // returns Collection<RawEvent>

    public function isAvailable(): bool;  // health check
}
```

```php
class RawEvent
{
    public function __construct(
        public string $title,
        public ?string $description,
        public ?Carbon $startsAt,
        public ?Carbon $endsAt,
        public ?string $venueName,
        public ?string $venueAddress,
        public string $sourceUrl,
        public string $sourceName,
        public ?string $imageUrl = null,
        public ?string $rawHtml = null,
        public array $rawData = [],
    ) {}
}
```

### 4.7 LLM Classification Prompt (Claude API)

```
You are an event classifier. Given an event's title and description, extract:

1. primary_category: exactly one of [Music, Art & Culture, Sports & Fitness, Food & Drink, Tech & Science, Business & Networking, Community & Social, Family & Kids, Outdoors & Adventure, Nightlife, Theater & Film, Workshops & Classes, Festivals, Other]
2. tags: array of 3-8 lowercase descriptive tags
3. price_range: "free" | "low" ($0-15) | "medium" ($15-50) | "high" ($50+) | "unknown"
4. age_restriction: "all_ages" | "18+" | "21+" | "kids" | "unknown"
5. setting: "indoor" | "outdoor" | "both" | "unknown"
6. formality: "casual" | "smart_casual" | "formal" | "unknown"

Respond ONLY with valid JSON. No explanation.

Event title: {{title}}
Event description: {{description}}
Venue: {{venue_name}}
```

---

## 5. Email Notification Design

### Structure (MJML template)

```
┌──────────────────────────────────┐
│  EventPulse Logo                 │
│  "Your events for [date range]"  │
├──────────────────────────────────┤
│                                  │
│  🎯 Top Picks for You            │
│  ┌─────────────────────────────┐ │
│  │ [Image]                     │ │
│  │ Event Title                 │ │
│  │ 📅 Sat, Apr 5 · 7:00 PM     │ │
│  │ 📍 Venue Name, Neighborhood │ │
│  │ 🏷️ Music · Jazz · Free       │ │
│  │ Brief description...        │ │
│  │ [👍] [👎] [🔖] [❌]          │ │
│  │ → View Event                │ │
│  └─────────────────────────────┘ │
│  (repeat for 5-6 events)        │
│                                  │
│  🔮 Something New to Try         │
│  ┌─────────────────────────────┐ │
│  │ (1-2 discovery events)      │ │
│  │ "We thought you might like" │ │
│  └─────────────────────────────┘ │
│                                  │
├──────────────────────────────────┤
│  [Update Preferences]            │
│  [Unsubscribe]                   │
└──────────────────────────────────┘
```

---

## 6. Non-Functional Requirements

| Aspect          | Target                                                         |
|-----------------|----------------------------------------------------------------|
| Latency         | Dashboard page load < 500ms, API responses < 200ms            |
| Throughput      | Support 10k+ events in DB, 1k+ active users                   |
| Email delivery  | Daily digest sent within 15-minute window (8:00–8:15 AM)      |
| Scraper uptime  | 95%+ success rate per source; alerting on consecutive failures |
| Data freshness  | Events ingested within 6h of appearing on source               |
| LLM cost control| Batch classify (max 500 events/day), cache classifications    |
| Privacy         | GDPR compliant; user data exportable/deletable                 |

---

## 7. Milestones

### Phase 1 — MVP (4-6 weeks)
- [ ] DB schema + migrations
- [ ] 2-3 scraper adapters (Eventbrite, 1 local site, generic HTML)
- [ ] Event pipeline (dedup + LLM classification + store)
- [ ] User registration + onboarding chat
- [ ] Basic recommendation engine (category + tag matching)
- [ ] Email digest notifications
- [ ] Feedback collection (reaction buttons in email)
- [ ] Minimal dashboard (saved events, profile view)

### Phase 2 — Refinement (2-4 weeks)
- [ ] Discovery engine with exploration budget
- [ ] Feedback loop + profile decay
- [ ] Discovery tracking + suppression logic
- [ ] More scraper adapters (3-5 total)
- [ ] Full dashboard (browse, search, filter)
- [ ] Notification settings UI

### Phase 3 — Scale (2-4 weeks)
- [ ] Push notifications (web push)
- [ ] Collaborative filtering (users who liked X also liked Y)
- [ ] Scraper monitoring dashboard (admin)
- [ ] Multi-city support
- [ ] A/B testing framework for recommendation weights
- [ ] Event dedup improvements (embedding-based similarity)

---

## 8. Open Questions

1. **Source legality**: Which sources allow scraping? Prioritize those with APIs (Eventbrite, Meetup) and public municipal data.
2. **City scope**: Start with Timișoara and expand. See `TIMISOARA_SOURCES.md` for the full source inventory (18 sources identified, 4 prioritized for MVP).
3. **LLM cost**: At ~500 events/day × $0.003/classification ≈ $1.50/day. Acceptable for MVP.
4. **Email deliverability**: Use a dedicated sending domain with proper DMARC/SPF/DKIM.
5. **User acquisition**: Consider a public event browsing page (no login) as an SEO/acquisition funnel.
