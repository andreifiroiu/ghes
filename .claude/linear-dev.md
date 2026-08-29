<!-- dev-flow project profile — read by the dev-flow:linear-dev skill. -->

# linear-dev profile — Ghes

- **Linear project:** "Ghes"
- **Team:** Cowork Timisoara
- **Issue key prefix:** COW
- **Sibling projects on the same team:** Linkerlee, Linkerlee Browser Extension,
  Clerq, PretulMeu, TimisoaraStartups.com, Workumi, CoworkTm HR Portal — the COW
  team carries all of them, so **filter by project "Ghes"**, never by team alone.
- **Status names:** `Backlog`, `Todo` (one word — not "To do"), `In Progress`,
  `In Review`, `Done`, `Canceled`.

## Branch naming

Use the `gitBranchName` Linear supplies when there is one. Otherwise
`feature/<short-slug>` — history shows `feature/scrapers` and `claude/<slug>-<id>`,
and CLAUDE.md documents `feature/*`, `fix/*`, `chore/*`. Base off `main`, not `dev`.

## Plan the full stack

Most surfaces in this repo exist **twice** — an Inertia web action and a REST API
action on the same controller — and the pair is easy to half-update:

- `EventController`: `index`/`apiIndex`, `show`/`apiShow`, `saved`/`apiSaved`.
  A filter added to one must be added to the other, or the API leaks what the web
  app hides. `browseQuery()` is the shared builder — put filters there, not in the
  action.
- Routes live in **both** `routes/web.php` and `routes/api.php`, each with its own
  `admin` group.

A typical change therefore covers: migration + `$fillable` + `casts()` on the model
+ factory · a query scope on the model (e.g. `Event::upcoming()`, `Event::visible()`)
applied at **every** call site · `EventResource` and/or `AdminEventResource` ·
a Form Request · the Inertia page under `resources/js/Pages/` · and a Pest feature test.

Scraper work is different: a new source is an adapter class implementing
`ScraperAdapter`, plus an entry in `adapter_registry`, plus a per-city entry under
`cities.<city>.sources` in `config/eventpulse.php`. No pipeline changes.

## Conventions to follow while implementing

- Admin authorization is a single gate, `access-admin`, defined in
  `AppServiceProvider` against the `eventpulse.admin_emails` allow-list. There is no
  role column and no policy classes — do not introduce either for a one-off screen.
- Events use UUID primary keys, so route-model binding keys are strings, and tests
  must never hardcode an id.
- Anything touching an external API (Claude, geocoding, a scraped site) goes on a
  named queue — `scraping`, `processing`, `ai`, `enrichment`, `notifications`.

## Project skills to activate

`pest-testing`, `laravel-best-practices`, `scout-development`, `configuring-horizon`,
`tailwindcss-development` — these live in this repo's own `.claude/skills/`.

**`inertia-react-development` is not installed here** despite CLAUDE.md and the Boost
guidelines telling you to activate it for Inertia client-side work. Do not go looking
for it; fall back to `search-docs` for Inertia v3 questions.
