<!--
  dev-flow project profile — read by the dev-flow:ship-it skill.
  Keep the headings; the skill refers to them by name.
-->

# ship-it profile — EventPulse

Personalised local event discovery: scrapes events from many providers, classifies
them with Claude, and emails curated digests. The governing concern is **event
identity** — the same real-world event arrives from several providers with
different titles, times and city spellings, and every downstream surface
(recommendations, discovery, digests, reactions) assumes one canonical row per
event. A dedup or merge regression is user-visible as duplicate emails.

**Stack:** Laravel 13 · PHP 8.3 (`composer.json`) / 8.4 local · Inertia 3 + React 19 + Tailwind 4 · PostgreSQL 16 (prod) · Redis + Horizon · Meilisearch via Scout · Pest 4 (**not** PHPUnit syntax) · Larastan 3 · Pint 1

**CI:** **There is no CI on this repo.** `.github/` holds only skill docs — no
`workflows/` directory. The local gates below are the only gates; nothing will
catch a mistake after the push.

## Branch and PR policy

- Feature branch: required. `main` and `dev` are never committed to directly.
- PR base: **`main`** for `claude/*` and most `feature/*` branches (PRs #1, #2, #3, #5).
  A `dev` branch exists and PR #4 targeted it, so the promotion flow is real but
  inconsistently used — **check `gh pr list` and ask if it is ambiguous** rather
  than assuming. CLAUDE.md says `develop`; the branch is actually named `dev`.
- Remote: `git@github.com:andreifiroiu/ghes.git` (repo slug is `ghes`, not `eventpulse`).
- Merge style: merge commits (`Merge pull request #N from …`), not squash.

## Format

```
vendor/bin/pint --dirty --format agent
```

No `pint.json` — the default `laravel` preset applies. Never run bare
`vendor/bin/pint --test`; scope it to changed files if you need a check-only pass.

## Static analysis and frontend gates

```
vendor/bin/phpstan analyse --no-progress --memory-limit=2G
```

- `--memory-limit=2G` is **required**. Without it the parallel worker dies with
  `Child process error (exit code 255)` and PHPStan reports a single bogus error
  plus "Result is incomplete" — which reads like a pass if you only skim the tail.
- **Pre-existing baseline: 34 errors on `main` (3cb488f).** The bar is zero *new*
  errors, so diff the error *sets*, never the counts:
  `phpstan --error-format=json`, flatten to `file::message`, `sort`, `comm -13`.
- Most of the baseline is one class: **Larastan does not read `casts()` for the
  `Event` model**, so `$event->starts_at` is inferred as `string` and every
  `->toDateTimeString()` / `->copy()` / `->isPast()` on it is an error. The model
  has no `@property` docblock. Adding one fixes the whole family — do not silence
  them individually with ignores.
- Frontend: `npm run build` only when a new page or entry was added (Vite 8 +
  `laravel-vite-plugin`). There is no eslint/prettier/tsc gate.

## Tests

**Database safety:** safe. `phpunit.xml` forces `DB_CONNECTION=sqlite`,
`DB_DATABASE=:memory:` — the lines are **active, not commented out** — so
`RefreshDatabase` cannot touch the dev database. But note the mismatch: prod is
PostgreSQL and CLAUDE.md invites JSONB operators and GIN indexes, while tests run
on **sqlite**. Any PG-only SQL is untestable here and will only fail in production.

- Runner: **Pest 4**, `it()`/`expect()` only. `tests/Pest.php` applies
  `RefreshDatabase` to `Feature` **only** — `Unit` tests get no database.
- Affected tests: `php artisan test --compact --filter=<Name>`
- Full suite: `php artisan test` (~110s, 540 tests). Required — dedup changes are
  cross-cutting.
- **Pre-existing failure baseline: 8 failures on `main`** — 2 in `Api/RecommendationTest`,
  2 in `Scraping/TeatruNationalTmScraperTest`, 1 in `Chat/OnboardingAgentTest`,
  3 in `Notification/EmailRendererTest`. Green here means "still exactly these 8",
  not zero. Confirm against `main` before reporting a regression.
- Fixtures: full factory set exists (`Event`, `EventSource`, `User`,
  `UserEventReaction`, `ScraperRun`, `ChatMessage`, `Notification`, `DiscoveryLog`).
  Use them; there are no `tests/Concerns/` helpers.
- Project-wide gate: none. `composer test` only wraps `artisan test` — Pint and
  PHPStan are not wired into it and must be run by hand.

## Records

- **No `CHANGELOG.md` and no `public-changelog.md`.** Do not invent either.
- `SPEC.md` is the load-bearing product record. Update it when a change contradicts
  something it asserts.
- `CLAUDE.md` when a new env var, artisan command, queue name or scraper adapter
  lands; `.env.example` alongside any new env var.
- Otherwise the PR body is the record.

## Commit style

- Conventional Commits, no scope: `feat: dedupe events across providers …`
- Body: prose paragraphs explaining **what was wrong before and what the change
  makes true**, then `- ` bullets for the individual fixes. Wrapped at ~76 chars.
  Most older commits have empty bodies; the recent, richer style is the target.
- Trailer: this repo **does** carry `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`
  and a `Claude-Session:` URL line.
- Footer: **no** "Generated with Claude Code" ad line. Do not add one.

## After the PR

Nothing. No public changelog, no announcement ritual.

## What breaks in this codebase

- **Timezone-as-UTC** — scrapers parsed Romanian local wall-clock time and stored
  it as if it were UTC, putting late-evening events on the wrong calendar day
  (fixed in IaBilet and ZileSiNopti). Any new adapter must parse in the city's
  `timezone` from `$cityConfig` and convert. Grep new adapters for `Carbon::parse`
  without a timezone argument.
- **Fingerprints that cannot collide** — the original exact-match fingerprint
  hashed the source URL, so it never matched across providers and dedup silently
  did nothing. A dedup key that includes anything provider-specific is broken by
  construction.
- **Insert-only source guards** — the old `source_url` uniqueness guard blocked
  every re-scrape and silently dropped recurring events sharing one URL. Re-scrape
  must be a lookup-and-update on `(source, id-or-url, occurrence)`, not an insert.
- **Check-then-insert races** — dedup writes must serialise per match key and
  retry on unique violation. Application-level uniqueness checks are races; prefer
  a DB constraint.
- **Silent no-op commands** — `ProcessEventsCommand` passed `Event` models into a
  `RawEvent` batch API, so every scheduled run did nothing and reported success.
  When a command's counters are always zero, suspect a type mismatch, not an empty queue.
- **Unpopulated bookkeeping columns** — `scraper_runs` had created/updated/skipped
  columns nobody wrote to. If you add a counter column, write it in the same PR.
- **Merged rows must stay visible-but-excluded** — merged events are flagged, never
  deleted, because sent digests and existing reactions still link to them. Any new
  query surface (listing, recommendation, discovery, Scout index) must exclude
  merged events, and any link to one must resolve to the survivor. Missing one
  surface is the recurring bug here.
- **Score clamping** — profile scores must be clamped to `[0.0, 1.0]` after every
  update (`max(0.0, min(1.0, $score))`).
- **Reaction uniqueness on merge** — moving reactions between events must honour
  the unique `(user, event)` constraint and must never re-apply an already-applied
  feedback delta.

## Frozen surfaces

- `phpunit.xml`'s sqlite in-memory `<env>` lines — removing them points the suite
  at the real database.
- The `Claude-Session:` and `Co-Authored-By:` commit trailers.
