<!-- dev-flow project profile — read by the dev-flow:public-changelog skill. -->

# public-changelog profile — Ghes

**Language:** Romanian — the whole app UI is Romanian (nav "Acasă / Evenimente /
Salvate / Profil", paginare "Înapoi / Înainte", filtre "Dată: / Toate datele"), so
release notes must match what the reader sees on screen.

**Audience:** oameni din Timișoara care caută evenimente — cei care primesc
recomandări, salvează evenimente și reglează ce le apare. Ghes nu are încă o
secțiune pentru organizatori, deci nu scrie pentru ei.

**Not public:** the admin panel (`/admin`) is internal, gated by an e-mail
allow-list. Changes to admin screens, scrapers, queues, Horizon, or the Meilisearch
index are **not** changelog material unless a user can see the result — "am adăugat
o sursă nouă de evenimente" is publishable; "am rescris ScraperOrchestrator" is not.

**Labels:** Nou / Îmbunătățit / Rezolvat / Modificare majoră

## Jargon glossary — internal name → what to write instead

- `RecommendationEngine`, `DiscoveryEngine` → *recomandările tale*, *descoperiri*
- `interest_profile`, `ProfileScorer` → *profilul tău de interese*
- `UserEventReaction`, `Reaction::Saved` → *reacțiile tale* — *mă interesează*,
  *salvat*, *nu mă interesează*
- scraper, adapter, `ScraperOrchestrator` → *sursele de evenimente* (never "scraper")
- `EventCategory` → *categorie* (use the Romanian label the chips show, not the
  lowercase enum value)
- `is_hidden` → don't name it; write *evenimentul nu mai apare*
- `OnboardingAgent`, onboarding chat → *conversația de la început*
- Inertia, Horizon, Meilisearch, Scout, Pest, Claude API → never appear in an entry

## Where in the product

Pagina de start, lista de evenimente, pagina unui eveniment, evenimentele salvate,
profilul, setările de notificări, conversația de la început, și e-mailurile cu
recomandări.

## File header

`public-changelog.md` does not exist yet. Create it starting with:

```markdown
# Ghes — Noutăți

Ce s-a schimbat în Ghes, pentru cei care caută evenimente în Timișoara.
```

## Also in this repo

There is no `CHANGELOG.md` and no `docs/`. `public-changelog.md` will be the only
changelog — do not add a contributor-facing one alongside it. `SPEC.md` is the
product spec and is not a release record.
