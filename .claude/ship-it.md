<!-- dev-flow project profile — read by the dev-flow:ship-it skill. -->

# ship-it profile — Ghes

Personalized local event discovery for Timișoara: scrapes events, classifies them with
Claude, recommends them per user. **Governing concern: query correctness across the
sqlite/PostgreSQL split.** Tests run on sqlite in-memory while production runs Postgres,
so a green suite is not proof a query works — and a wrong query's user-facing symptom is
a silently empty event list.

**Stack:** Laravel 13.17 · PHP 8.4 · Inertia v3 + React 19 + Tailwind v4 (hand-rolled
shadcn-style primitives in `resources/js/Components/ui/`) · PostgreSQL + Redis/Horizon ·
Meilisearch via Scout · Pest 4. CLAUDE.md still claims "Laravel 12, PHP 8.3" — stale.

**CI: there is no CI on this repo.** `.github/` holds only skill definitions, no
workflows. The local gates below are the only gates.

## Branch and PR policy

- Feature branch required. Never commit to `main`.
- PR base: **`main`** — 10 of the 11 PRs to date target it (only #4 targeted `dev`).
  CLAUDE.md documents a `main`/`develop` split, but no `develop` exists. The observed
  base wins.
- `dev` still exists but is now **identical to `main`** (PR #5 promoted it, and
  everything since has been branched off `main`). It is not a staging branch in
  practice — do not base work on it.
- Work also arrives from Superset worktrees in parallel, so `origin/main` can move
  mid-session. `git fetch` and rebase before pushing; a branch cut an hour ago may
  already be stale.
- Remote `andreifiroiu/ghes` (SSH). Merge style: merge commits
  (`Merge pull request #N from andreifiroiu/<branch>`), not squash.

## Format

`vendor/bin/pint --dirty --format agent` — no `pint.json`, so the default `laravel` preset.

**Never run Pint repo-wide.** 14 pre-existing files fail it (six `0001_01_01_*`
migrations, `NotificationFactory`, `bootstrap/providers.php`, `HorizonServiceProvider`,
the three DTOs, `ProfileScorer`). `--dirty` is the only way to get a meaningful result.

## Static analysis and frontend gates

- `vendor/bin/phpstan analyse --memory-limit=1G` — **the flag is not optional.** At PHP's
  default 128M the parallel worker dies with *"Child process error: PHPStan process
  crashed because it reached configured PHP memory limit: 128M"* and prints
  `[ERROR] Found 1 error`. That line is a crash, not a result.
- Level 6, larastan, `paths: app/` only — `tests/` and `database/` are not analysed.
- No baseline file, but **5 pre-existing errors**: `ProfileUpdateRequest.php:22`,
  `RunScraperJob.php:64`, `OnboardingAgent.php:136,138,147`. The bar is "still 5", not
  zero. Diff the error *sets*, not the count — a scratch worktree off `origin/main`
  with `vendor/` symlinked in runs PHPStan against the base without stashing.
- Frontend: no ESLint, Prettier, or TypeScript. `npm run build` (Vite) is the only gate.

## Tests

**Database safety:** `phpunit.xml` forces `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`,
`SCOUT_DRIVER=null`, and those lines are **active** — `RefreshDatabase` cannot reach the
dev Postgres database (`bf_ghes`). Safe, but it is the source of the first trap below.

- Pest 4 exclusively. PHPUnit 12 is installed only as Pest's engine — never PHPUnit syntax.
- `tests/Pest.php` applies `RefreshDatabase` to `Feature` only; `tests/Unit` gets no DB.
- Affected: `php artisan test --compact <path>` or `--filter=<name>`.
- Full: `php artisan test --compact` — ~90s, currently **784 passed, 1 skipped**. One
  long-standing skip is expected; "1 skipped" is green.
- `phpunit.xml` also forces `MAIL_MAILER=array`, so no test can send real mail.
- Fixtures: seven factories (Event, User, UserEventReaction, ChatMessage, DiscoveryLog,
  Notification, ScraperRun); no `tests/Concerns` or `tests/Support`. Copy
  `tests/Feature/Admin/AdminEventTest.php` for an admin-gated test (it shows the
  `config(['eventpulse.admin_emails' => [$admin->email]])` idiom) or
  `tests/Feature/Api/EventsIndexTest.php` for Inertia prop assertions.
- Any test rendering an Inertia page needs `$this->withoutVite()` in `beforeEach`.
- Project-wide gate: none. `composer test` is `config:clear` + `artisan test`; there is
  no `composer ci:check`.

## Records

None. No `CHANGELOG.md`, no `docs/`. `SPEC.md` is the 558-line product spec, not updated
per change. The PR body is the record — do not invent a contributor changelog.
User-facing notes go to `public-changelog.md` via that skill.

## Commit style

- Conventional Commits, no scope, lowercase after the type.
- Body: `fix:` commits **name the root cause first** ("Filter chips sent capitalized
  values … so the query matched nothing") — explain why it broke, not what changed.
  `feat:` commits are often subject-only.
- Trailer: this repo **does** carry `Co-Authored-By: Claude <model> <noreply@anthropic.com>`.
- Footer: **no** "Generated with Claude Code" line — zero occurrences in 100 commits. One
  old commit carries a bare `https://claude.ai/code/session_…` URL; do not copy that.

## After the PR

Run the `dev-flow:public-changelog` skill — `public-changelog.md` is the user-facing
record and `.claude/public-changelog.md` carries its rules (Romanian; admin, scraper,
queue and index work is internal and gets no entry). A change that turns out to be
internal-only writes nothing, which is a valid outcome.

## What breaks in this codebase

- **JSON-column predicates pass on sqlite, misbehave on Postgres.** Two instances so
  far. `DiscoveryEngine::collaborativelyPopularCategories` was rewritten off
  `where('interest_profile->'.$category, '>=', $threshold)` onto a reactions join,
  commented *"portable (no JSON column queries)"*. And `ProfileDecayer::decayAll()`
  filtered with `where('interest_profile', '!=', '{}')` — sqlite compares it as text,
  Postgres has no equality operator for `json` and throws *"operator does not exist:
  json <> unknown"*, so the weekly decay had never once run. Before any
  `where('json_col…')`, `whereJsonContains`, or a bare `!=` on a json column, assume the
  suite will lie; filter in PHP or join instead.
- **API-Resource pagination shape.** `Resource::collection($paginator)` resolves to
  `{data, links:{first,last,prev,next}, meta:{current_page,…,links:[…]}}`. `links` is an
  *object*, so `links.map(...)` is truthy then throws, white-screening the page — two
  admin index pages shipped exactly that. Use `Components/Pagination.jsx` (reads
  `meta.links`); for prev/next use `x.links.prev` and `x.meta.last_page`.
- **Inertia shared props are minimal** — `HandleInertiaRequests::share()` carries only
  `auth` and `flash`, and `flash` was absent entirely until recently, so every
  `->with('success', …)` was silently invisible. Adding a redirect-with-message means
  checking the layout you land on renders `flash`.
- **Enum-backed filter values are lowercase** — `EventCategory` is backed by `technology`,
  not `Tech`; capitalized chip values once matched nothing.
  `resources/js/lib/categories.js` is the frontend's single source.
- **A `match` on a validated string still needs a `default`.** PHPStan level 6 cannot
  narrow `$request->validate([… Rule::in([…])])` to literals, so deleting the
  "unreachable" arm is a *new* error. Throw in `default` instead.
- **Tests never reveal a pending migration** — sqlite rebuilds per run, so a migration
  unapplied to `bf_ghes` stays invisible. Run `php artisan migrate:status` before
  assuming a column exists.
- **Removing a reaction is `DELETE /feedback`** — POST requires a non-null reaction;
  `reaction: null` 422s.
- **`.env.example` drifts behind `config/`, and the gap is invisible locally.** Google
  OAuth and web push both shipped with no entry in `.env.example`, so a deploy seeded
  from it came up misconfigured — and `PushSender` no-ops when its VAPID keys are
  absent, so that half failed *silently*. When adding an `env()` call with no default,
  add the key to `.env.example` in the same commit. To audit:
  `comm -23 <(grep -rhoE "env\('[A-Z0-9_]+'" config/ | sed -E "s/env\('//; s/'//" | sort -u) <(grep -oE "^[A-Z0-9_]+" .env.example | sort -u)`
- **`Mail::fake()` proves nothing about the digest.** `NotificationDispatcher` sends
  via `Mail::html()`, a raw message, and `MailFake` only records *mailables* — so
  `assertSentCount()` reports 0 and `assertNothingSent()` passes even on a real send.
  Assert on the notification's own `sent_at`, which only a real dispatch writes.
- **Digest delivery needs a worker now.** `eventpulse:send-notifications` queues one
  `SendNotificationJob` per digest (tries 3, backoff, `notifications` queue) instead of
  sending inline; with Horizon stopped, nothing is delivered at all. `--sync` restores
  the inline path for verifying credentials without a worker.
- **Mailgun is in the EU region.** `services.mailgun.endpoint` defaults to
  `api.eu.mailgun.net`; an EU domain queried against the US host answers **401**, which
  reads like a bad key. `MAIL_FROM_ADDRESS` must also sit on the verified sending
  domain or Mailgun 403s it.
- **`RecommendationEngineTest::it ranks higher-scored events first` is flaky and fails
  silently.** Its `expect()` sits behind `if ($musicPos !== false && $techPos !== false)`,
  so when discovery displaces an event from the batch the test reports **risky** (zero
  assertions) instead of failing — seen in ~2 of 6 runs on `main` too. A run showing
  `1 risky` in `tests/Feature/Services/Recommendation` is pre-existing, not your change;
  confirm by re-running before you go hunting.

## Conventions worth knowing

- Every class in `app/Console/Commands` uses the `LogsConsoleOutput` trait, which
  mirrors terminal output into the log channels. An arch test in
  `tests/Feature/Console/ConsoleOutputLoggingTest.php` fails if a new command omits it,
  so add the trait rather than deleting the assertion.

## Frozen surfaces

- `config/eventpulse.php` and every `eventpulse.*` key keep the old product name. The
  product is now Ghes; the keys are read in dozens of places and in tests. Not cleanup.
- The 5 PHPStan errors and 14 Pint-dirty files above — fixing them inflates every diff
  and hides the real change.
