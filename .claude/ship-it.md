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

- Feature branch required; day-to-day work sits on `dev`, which **is** pushed and in
  sync with `origin/dev` but is 11 commits ahead of `origin/main` and has never been
  merged. Never commit to `main`.
- PR base: **`main`** — all three PRs to date target it. CLAUDE.md documents a
  `main`/`develop` split, but no `develop` exists and nothing has ever been based on
  `dev`. The observed base wins.
- Remote `andreifiroiu/ghes` (SSH). Merge style: merge commits
  (`Merge pull request #N from andreifiroiu/<branch>`), not squash.

## Format

`vendor/bin/pint --dirty --format agent` — no `pint.json`, so the default `laravel` preset.

**Never run Pint repo-wide.** ~16 pre-existing files fail it (all seven `0001_01_01_*`
migrations, `EventFactory`, `NotificationFactory`, `bootstrap/app.php`,
`bootstrap/providers.php`, `HorizonServiceProvider`, the three DTOs, `ProfileScorer`).
`--dirty` is the only way to get a meaningful result.

## Static analysis and frontend gates

- `vendor/bin/phpstan analyse --memory-limit=1G` — **the flag is not optional.** At PHP's
  default 128M the parallel worker dies with *"Child process error: PHPStan process
  crashed because it reached configured PHP memory limit: 128M"* and prints
  `[ERROR] Found 1 error`. That line is a crash, not a result.
- Level 6, larastan, `paths: app/` only — `tests/` and `database/` are not analysed.
- No baseline file, but **6 pre-existing errors**: `ProcessEventsCommand.php:31`,
  `ProfileUpdateRequest.php:22`, `RunScraperJob.php:64`,
  `OnboardingAgent.php:136,138,147`. The bar is "still 6", not zero.
- Frontend: no ESLint, Prettier, or TypeScript. `npm run build` (Vite) is the only gate.

## Tests

**Database safety:** `phpunit.xml` forces `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`,
`SCOUT_DRIVER=null`, and those lines are **active** — `RefreshDatabase` cannot reach the
dev Postgres database (`bf_ghes`). Safe, but it is the source of the first trap below.

- Pest 4 exclusively. PHPUnit 12 is installed only as Pest's engine — never PHPUnit syntax.
- `tests/Pest.php` applies `RefreshDatabase` to `Feature` only; `tests/Unit` gets no DB.
- Affected: `php artisan test --compact <path>` or `--filter=<name>`.
- Full: `php artisan test --compact` — ~50s, currently **605 passed, 1 skipped**. One
  long-standing skip is expected; "1 skipped" is green.
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

## What breaks in this codebase

- **JSON-column predicates pass on sqlite, misbehave on Postgres.**
  `DiscoveryEngine::collaborativelyPopularCategories` was rewritten off
  `where('interest_profile->'.$category, '>=', $threshold)` onto a reactions join,
  commented *"portable (no JSON column queries)"*. Before any `where('json_col->key', …)`,
  `whereJsonContains`, or `whereRaw`, assume the suite will lie.
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
- **`RecommendationEngineTest::it ranks higher-scored events first` is flaky and fails
  silently.** Its `expect()` sits behind `if ($musicPos !== false && $techPos !== false)`,
  so when discovery displaces an event from the batch the test reports **risky** (zero
  assertions) instead of failing — seen in ~2 of 6 runs on `main` too. A run showing
  `1 risky` in `tests/Feature/Services/Recommendation` is pre-existing, not your change;
  confirm by re-running before you go hunting.

## Frozen surfaces

- `config/eventpulse.php` and every `eventpulse.*` key keep the old product name. The
  product is now Ghes; the keys are read in dozens of places and in tests. Not cleanup.
- The 6 PHPStan errors and ~16 Pint-dirty files above — fixing them inflates every diff
  and hides the real change.
