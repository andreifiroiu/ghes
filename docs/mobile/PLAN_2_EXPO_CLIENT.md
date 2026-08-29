# Plan 2 — Ghes mobile app (Expo / React Native)

**Repo:** new — `ghes-mobile` (does not exist yet) · **Companion plan:** `PLAN_1_BACKEND_API.md`

---

## Context

Ghes is a Romanian personalised local event discovery platform, Timișoara-first. It scrapes events
from ~15 sources, classifies them with Claude, builds a per-user interest profile from an LLM chat
onboarding, and sends a daily digest of 5–8 events — roughly 70% strong matches, 20% moderate, and
10% deliberate *discovery* picks meant to broaden horizons. Users react (👍 / 👎), save, and every
reaction and click feeds back into the recommendation engine.

The app is a **thin client** against the `/api/v1` surface built in Plan 1. The Laravel monolith —
scrapers, Horizon queues, Meilisearch, the Claude pipeline — stays where it is.

**Why Expo, not native Swift + Kotlin:** two codebases forever for a one-maintainer project, on an
app that needs no exotic device capability. Expo gives EAS Build/Submit (no local Xcode), OTA
updates, and reuses the React/Tailwind skill already in the main repo.

**Why a separate repo:** the client shares no code with the Inertia frontend — React web and React
Native have no common components — and bundling a Laravel monolith onto a phone is not a thing.
The shared artefact is the OpenAPI contract, not source.

**Phase 1 scope: full parity with the web app**, admin excluded.

---

## Corrections to the obvious assumptions

Verified against the main repo. The client must follow the code, not intuition.

- **Categories are 14 lowercase values**, not a prettified list. From
  `app/Enums/EventCategory.php`, with Romanian labels in `resources/js/lib/categories.js`:
  `music` Muzică · `technology` Tech · `sports` Sport · `arts` Artă · `food` Gastronomie ·
  `nightlife` Viața de noapte · `business` Business · `health` Sănătate · `education` Educație ·
  `family` Familie · `community` Comunitate · `film` Film · `literature` Literatură ·
  `other` Altele.
  **Do not hand-write this list** — generate the values from the OpenAPI enum and keep the RO
  labels in the i18n file. A chip that sends `Tech` when the enum is backed by `technology`
  silently returns nothing; that bug is already on record in the main repo.
- **There are two reactions, not four.** `app/Enums/Reaction.php` has only `interested`
  ("Mă interesează") and `not_interested` ("Nu-i pentru mine"). Saving is a **separate**
  `event_bookmarks` table, deliberately not a reaction. **"Hide events like this" does not exist as
  a distinct signal** — `not_interested` already removes the event from browse. Ship two reactions
  plus save; do not invent a third.
- **`NotificationChannel` is `email | push | both` — there is no `none`.**
  `NotificationFrequency` is `realtime | daily | weekly`. `discovery_openness` is not in the
  settings payload today (it lives on the profile page). Render whatever the spec actually exposes
  and feature-flag the rest.
- **Page size is 18** (`config('eventpulse.pagination.events')`), not 20. Read it from
  `GET /meta`; never hardcode it.
- The web Settings page is still half-English ("Notification Preferences", "Save Settings"). The
  app is **100% Romanian, no exceptions** — that is a parity *improvement*, not a regression.

---

## Hard prerequisites (from Plan 1)

Do not start the client until these exist on the dev host:

- **Wave 0** — `/api/v1/` live, the `ApiResponse` envelope, the error shape, and `GET /api/v1/meta`
- **Wave 0.5** — `openapi/v1.yaml`, verified against real responses

Everything else can be mocked against the spec. **Push (Plan 1 workstream D) is not a blocker** —
build against the read and write surface, wire push when D lands.

---

## Stack

| Concern | Choice | Why / trade-off |
|---|---|---|
| Runtime | **Expo SDK (managed) + dev clients** | Native modules (push, secure store, Google sign-in) rule out Expo Go for real testing; dev clients keep the managed workflow. No ejecting. |
| Language | **TypeScript, strict** | The web is untyped JSX. A typed client with generated API types is the biggest quality win available here for free. |
| Routing | **Expo Router**, typed routes | File-based routes map 1:1 to the web's page list, and deep links come free — decisive, given push is the retention engine. |
| Styling | **NativeWind v4** | The design language lives in Tailwind classes on the web; this ports spacing and colour by eye and keeps one token vocabulary. Trade-off: a Metro/Babel dependency and occasional class gaps — fall back to `StyleSheet` for animated or hot paths. |
| UI primitives | **React Native Reusables**, copied in rather than depended on | Mirrors the web's hand-copied-shadcn convention exactly (`resources/js/Components/ui/`). Owning the source beats fighting a kit's opinions across 11 screens. No Tamagui/Gluestack. |
| Server state | **TanStack Query v5** + a persister | Infinite queries, stale-while-revalidate, optimistic mutations and offline persistence in one library. It *is* the caching strategy. |
| HTTP | **`ky`** | Small, `fetch`-native, first-class hooks for the auth/refresh interceptor. Not axios. |
| API types | **`openapi-typescript`** → `src/api/schema.d.ts`, checked in | Types only, no client codegen — hand-written query hooks read better and let us encode the envelope quirks once. |
| Validation | **zod**, at the seams only | Auth responses, the event payload, chat, settings. Not every paginated row — that costs CPU on scroll for little gain. Forms via `react-hook-form` + `zodResolver`. |
| Token storage | **`expo-secure-store`** | Keychain / Keystore. Never AsyncStorage for bearer tokens. |
| KV / cache | **`react-native-mmkv`** | Sync reads, so there's no async gate before first paint; backs the query persister and the analytics queue. **`expo-sqlite` is overkill** — there is no relational offline model here, just cached JSON. |
| Push | **`expo-notifications`** + Expo push | One integration for APNs and FCM; matches Plan 1's server choice. |
| Images | **`expo-image`** | Disk + memory caching and placeholders. Event cards are image-heavy, from ~15 scrapers of varying quality. |
| Lists | **`@shopify/flash-list`** | The browse screen is an infinite paginated image list; FlatList recycling is not enough. |
| Animation | **Reanimated + Gesture Handler** | Swipe-to-react and the chat typing indicator. |
| Calendar | **`expo-calendar`**, `.ics` share as fallback | A native "Adaugă în calendar" that actually adds the event beats downloading a file into a mobile Files app. |
| External links | **`expo-web-browser`** | Ticket links open in-app so the user comes back. Always via the tracked click endpoint. |
| Google sign-in | **`expo-auth-session`** + ID-token exchange | Avoids the deprecated native-module config dance in a managed workflow. |
| Biometrics | **`expo-local-authentication`** | Optional local unlock. |
| i18n | **i18next + react-i18next**, **date-fns** with the `ro` locale | `Intl` would do for numbers, but date-fns gives the "mâine" / "sâmbătă seara" control we need. |
| Share | RN's built-in `Share` | Native sheet; matches the web `ShareButton`. |
| Haptics | **`expo-haptics`** | The cheapest "feels native" win, on reactions and saves. |
| Monitoring | **Sentry** with `expo-updates` release tagging | OTA makes "which JS bundle crashed" unanswerable otherwise. |
| Testing | **Jest + Testing Library**, **Maestro** for E2E | Maestro over Detox: YAML flows, runs on EAS, far less setup. |
| Lint | ESLint (`eslint-config-expo`) + Prettier | — |

**No map SDK in v1.** Render a static tile thumbnail (or just a venue card) plus a "Deschide în
hartă" button handing off to Apple/Google Maps via `Linking`. A real `react-native-maps` view costs
an API key, a Google billing account and bundle size for one read-only pin; the web only shows the
map on desktop anyway. Revisit if a "map of tonight" browse mode gets designed.

**Explicit rejections:** Redux/Zustand for server state (TanStack owns it), `react-native-firebase`
(drags in the native config burden Expo push avoids), Tamagui, `expo-sqlite`, streaming/SSE chat
(the backend does not stream).

---

## Structure

```
ghes-mobile/
├─ app/                                  # Expo Router
│  ├─ _layout.tsx                        # providers: Query, i18n, Auth, Theme, Sentry, splash gate
│  ├─ (public)/  index.tsx  login.tsx  register.tsx
│  ├─ (onboarding)/  _layout.tsx  chat.tsx
│  ├─ (app)/
│  │  ├─ _layout.tsx                     # auth + onboarding gate
│  │  ├─ (tabs)/  index.tsx  events.tsx  saved.tsx  profile.tsx
│  │  ├─ events/[id].tsx                 # deep-link target
│  │  ├─ profile/chat.tsx
│  │  ├─ settings/notifications.tsx  settings/account.tsx
│  │  ├─ notifications.tsx               # digest history
│  │  └─ recommendations/history.tsx
│  └─ modal/filters.tsx
└─ src/
   ├─ api/       schema.d.ts (generated)  client.ts  errors.ts  paginated.ts  endpoints/
   ├─ queries/   keys.ts + one hook file per resource
   ├─ auth/      AuthProvider  tokenStore  refresh  google  biometrics
   ├─ components/  ui/  events/  chat/  feedback/
   ├─ i18n/      index.ts  ro.json  en.json
   ├─ lib/       dates  price  categories  sources  haptics  deeplinks  cn
   ├─ analytics/ queue  useImpressionTracker  flush
   ├─ offline/   persister  mutationQueue  netinfo
   ├─ push/      register  handlers  channels
   └─ theme/     tokens  colors
```

`src/lib/*` are typed **ports** of `resources/js/lib/{price,categories,dates,sources}.js`. Two
rules there must be carried over verbatim, because both encode a real data quirk:

- **`formatPrice`**: a `price_min` of `0` that `is_free` does not confirm is *not* a price — return
  null and omit the line, rather than rendering "De la 0 RON". Admins can type 0 without ticking
  free, and some scraper adapters return `0.0` for "Gratuit" with `isFree` null.
- **`formatEventDate`**: a `00:00` clock time means *the date is known but the time is not* —
  scraped sources publish date-only events as local midnight. Render a bare date. Showing
  "Miercuri, 00:00" invents a commitment the user does not have.

---

## Screens

Brand tokens: `#0A1128` Deep Midnight Navy (surfaces, text), `#FF5733` Electric Persimmon (every
primary action — "the nudge"), `#F8F9FA` off-white. The web mixes in a stray `indigo-600` for
active chips; **the app standardises on persimmon** and drops indigo — the brand manual names three
colours.

Tab bar mirrors `AppLayout.jsx`: **Acasă · Evenimente · Salvate · Profil**, with
`useSafeAreaInsets`, lucide icons, persimmon active tint.

| Screen | API | Notes |
|---|---|---|
| **Landing** | — | Brand screen: *"Orașul îți dă ghes. Tu ce faci diseară?"* → Intră în cont / Înregistrează-te / **Continuă ca vizitator**. Guest mode matters: the web `/events` is public. Guests land on Evenimente with a signup banner and reactions hidden. |
| **Login / Register** | `POST auth/login\|register\|oauth/google` | `react-hook-form` + zod, RO errors, `textContentType` so the Keychain offers passwords, "Continuă cu Google" above a divider. On success → onboarding gate. |
| **Acasă** | `GET recommendations` | Two sections in one list: *Recomandate pentru tine* and *Descoperă ceva nou*, the latter badged so the 10% novel slice reads as intentional rather than as a bad match. Pull-to-refresh; no infinite scroll (it is a fixed 5–8 item digest). Link to *Recomandări anterioare*. |
| **Evenimente** | `GET events?search&category&city&date&range` | FlashList + `useInfiniteQuery`. Debounced search (350 ms), category chips from `GET /meta`, native date picker plus **Azi / Mâine / Weekend** quick chips — a mobile affordance the web lacks and the most useful native addition here. **Swipe-to-react**: right 👍, left 👎, with haptics and an "Anulează" toast; buttons stay on the card, swipe is the accelerator. Long-press → quick actions sheet. |
| **Detaliu eveniment** | `GET events/{id}`, `POST events/{id}/click` | The densest parity target (`Events/Show.jsx` is ~500 lines). Hero image with a collapsing header, category badge, RO date/time, venue + address, price, description, tags. Venue block with a static map thumbnail when lat/lng exist, else a plain address card, plus "Deschide în hartă" and "Copiază adresa". **One ticket button per `sources[]` entry** — *Vezi pe {source}* — each going through the click endpoint, never `source_url` directly. "Adaugă în calendar" only when `starts_at` exists (mirrors the web's `.ics` route, which 404s for undated events). Sticky bottom bar: reactions + save + share. |
| **Salvate** | `GET events/saved` | Same list component. Unsaving animates the row out with an undo toast. |
| **Profil** | `GET profile`, `GET profile/stats`, `PUT profile` | Account card with email-verification state and "Retrimite emailul", the interest-profile bars and tags (port `ProfilePreviewCard`), stats, and links to the profile chat, notification settings, and account screen. |
| **Setări notificări** | `GET\|PUT settings/notifications`, `POST\|DELETE devices` | Channel cards (Email / Push / Ambele), frequency control (În timp real / Zilnic / Săptămânal), and a push block showing **real device state** — granted, or denied with a `Linking.openSettings()` shortcut. All Romanian. |
| **Istoric notificări** | `GET notifications` | Past digests, each expanding to the events it carried, so a missed push is still findable. Cheap parity win over email-only history. |
| **Cont și confidențialitate** | `DELETE account`, `POST auth/logout-all` | Logout (with log-out-everywhere), biometric toggle, language, policy links, version + update channel, and **Șterge contul** — typed confirmation, an explicit list of what is deleted, then wipe SecureStore + MMKV + query cache, unregister the push token, route to Landing. |

---

## Auth flow

**Storage.** Access + refresh tokens in `expo-secure-store` (`WHEN_UNLOCKED_THIS_DEVICE_ONLY` — a
bearer token has no business in an iCloud backup). The user object is cached in MMKV for instant
first paint, but **the cached user is a hint, never proof**.

**Cold start** — this is where most apps get it wrong:

1. Hold the splash (`SplashScreen.preventAutoHideAsync`).
2. No tokens → Landing.
3. Optimistically render the tab shell from the cached user *while* firing `GET /profile` as the
   session probe. **Presence of a token is not authentication.**
4. `200` → hydrate, apply the onboarding gate. `401` → one refresh attempt; still `401` → wipe and
   go to Landing with *"Sesiunea a expirat. Intră din nou în cont."*
   **Network error → stay authenticated in offline mode**, cached data plus a banner. Never log
   someone out because the plane has no wifi.

**Refresh interceptor.** A `ky` `afterResponse` hook: on `401` (and never on the refresh endpoint
itself), call a **single-flight** refresh promise — concurrent 401s all await the same in-flight
refresh, then replay once each, guarded by a per-request `retried` flag. A failed refresh emits one
global `sessionExpired` event. Note that the refresh token is ability-scoped server-side: it works
*only* on `POST /auth/refresh`.

**Google.** `expo-auth-session` PKCE → ID token → `POST /auth/oauth/google` → our Sanctum pair.
Separate iOS / Android / web OAuth client ids via `app.config.ts`.

> ⚠️ **If Google sign-in ships on iOS, Sign in with Apple becomes mandatory** (App Store guideline
> 4.8). Budget `expo-apple-authentication` and a matching `POST /auth/oauth/apple` endpoint into the
> *same* milestone — tell the backend. Missing this is the single most common first-submission
> rejection.

**Biometric unlock.** Opt-in. Tokens stay in SecureStore; after >15 minutes backgrounded, a lock
overlay requires `expo-local-authentication`. It is a **local privacy gate only** — it never
substitutes for server validation, and it must always offer "Folosește parola contului" as an
escape.

**Onboarding gate.** `(app)/_layout.tsx` redirects to `(onboarding)/chat` while
`user.onboarding_completed === false`, and `(onboarding)/_layout.tsx` redirects out when it flips.
The flag comes from `GET /profile`, never from a local cache alone, or a reinstall strands the user.
A deep link arriving while un-onboarded is stashed and resumed after onboarding.

---

## The chat screens

The hard constraint: **plain request/response, multi-second latency, no streaming.** Design around
the wait rather than pretending it is not there.

- **Optimistic user bubble** appended immediately with `status: 'sending'`, input cleared, selection
  haptic. The server echoes back both the user and assistant messages; reconcile by replacing the
  temp bubble with the server's (which carries the real id and timestamp).
- **Typing indicator as a real assistant bubble** with three animated dots, so the wait sits
  *inside* the conversation rather than under a spinner overlay. After ~6 s swap the caption to
  "Mă gândesc…", after ~15 s to "Încă lucrez la asta…". This alone turns a scary wait into a
  legible one.
- **Timeout and retry:** 45 s abort. On failure the user bubble goes `status: 'failed'` with a
  "Reîncearcă" target — **the typed text is never lost**. No auto-retry: a duplicate LLM turn
  corrupts the conversation and costs money.
- **Send is disabled while a turn is in flight** — the backend flow is turn-based and cannot absorb
  interleaved messages.
- **Keyboard:** `KeyboardAvoidingView` (padding on iOS, height on Android), an inverted list so new
  messages pin to the bottom without scroll math, `keyboardShouldPersistTaps="handled"`, input bar
  above the safe-area inset.
- **Confirm-profile card.** When the response reports onboarding complete, a persimmon card slides
  up — *"Gata! Uite ce am înțeles despre tine."* — with the profile bars and tags inline, and two
  actions: *Confirmă și continuă* (`POST onboarding/confirm`) and *Mai am de adăugat*.
  The web confirms first and shows the profile after; **the app previews first and confirms second**,
  which is strictly better and worth back-porting to the web.
- **Profile chat** is the same component with `context=profile_update`, plus an "Aplică
  modificările" action. Placeholder copy ported from the web: *„M-am apucat de ceramică" sau „nu mai
  vreau evenimente de networking"*.
- History loads from `GET /chat/history?context=…` on mount, which also recovers a turn completed
  while the app was backgrounded.

---

## Offline & caching

TanStack Query persisted to MMKV, `gcTime`/`maxAge` 24 h, with a `buster` keyed to the app version
so a schema change cannot resurrect a stale shape.

| Data | Strategy | staleTime |
|---|---|---|
| `GET recommendations` | cache-first, background revalidate; persisted | 30 min |
| `GET events` (list) | network-first with cached fallback; **page 1 only** persisted | 5 min |
| `GET events/{id}` | cache-first, persisted; seeded from list data via `setQueryData` so taps open instantly | 15 min |
| `GET events/saved` | cache-first, persisted — the offline-critical screen | 10 min |
| `GET profile`, `profile/stats` | cache-first, persisted | 10 min |
| `GET settings/notifications` | cache-first | 1 h |
| `GET chat/history` | network-first, **not persisted** | 0 |

**The offline contract, stated plainly:** with no signal the user can open the app, read today's
recommendations, browse saved events, open any event they have already seen (with cached images),
and react or save — those writes land later. They cannot search, paginate, or chat.

**Images**: `expo-image` disk cache, plus prefetching the first ~8 recommendation images once the
digest query resolves, so the daily open is instant. Cap the cache; offer "Golește memoria cache" on
the account screen.

**Queue reactions and saves — yes.** They are the product's feedback loop; a silently dropped
reaction corrupts the interest profile, and nothing surfaces the loss. Implementation: TanStack
`MutationCache` with `setMutationDefaults` for the reaction and bookmark keys, `onMutate` patching
every cached list containing that event, `onError` rolling back with the RO status message ported
from `resources/js/lib/feedback.js`, plus paused-mutation persistence and `resumePausedMutations()`
on reconnect and on foreground. **Dedupe by `(event_id, kind)` — last intent wins**, so three
offline toggles replay as one write. Cap the queue at 200, dropping oldest with a Sentry breadcrumb.

**Do not queue:** chat messages (turn-ordered, LLM-costed, and a message delivered six hours later
out of context is worse than none) or profile edits (last-write-wins across devices is a genuine
conflict). Analytics has its own queue.

---

## Push

- **Registration timing.** Never at first launch. Prime *after* onboarding, on a purpose-built
  screen — *"Îți dăm ghes când găsim ceva bun?"* — with the frequency promise and Da / Mai târziu.
  Only on "Da" call `requestPermissionsAsync`: iOS gives you one prompt, ever. Also reachable from
  Settings; re-prompt at most once, ~14 days later, for engaged users only.
- **Device registration.** On grant, `getExpoPushTokenAsync` → `POST /devices` with token, platform,
  app version, locale, timezone and the persisted `install_id`. Re-post on cold start when the token
  changes (they rotate) and on login. `DELETE /devices` on logout and account deletion — otherwise
  the cron keeps pushing a digest to a signed-out phone.
- **The `install_id`.** Generate a UUID once, store it in MMKV, and send it on every device
  registration. Plan 1 uses it to suppress a *web* push subscription on the same handset — without
  it, someone with both the PWA and the app installed gets the digest twice on one device.
- **Opt-out symmetry.** The channel setting is server truth; the OS permission is device truth. Show
  both. If the OS permission has been revoked, detect it on foreground and `PUT
  /settings/notifications` down from `push`/`both`, so the cron stops sending into the void.
- **Deep links.** Scheme `ghes://` plus universal/app links. Payload carries
  `{type, event_id?, notification_id}`. Handle all three entry paths: cold start
  (`getLastNotificationResponseAsync`), background tap (`addNotificationResponseReceivedListener`),
  and foreground (an in-app banner via `setNotificationHandler` — never a modal). Every open reports
  activity with `from=push`, which `ActivitySurface::fromRequest` already understands.
- **Lock-screen actions.** An iOS `digest` category with *Salvează* and *Nu mă interesează*, firing
  the same mutations as in-app — training the model from the lock screen is the most on-brand push
  feature available.
- **Android channels** created at startup: `digest` (default importance, persimmon light), 
  `reminders` (low), `system` (min). Adaptive monochrome notification icon.
- **Badge** set on receipt, cleared on open. Android badges are launcher-dependent — never treat
  them as reliable.

---

## i18n & formatting

- **Romanian only in v1.** `en.json` exists as a stub so expansion is not a rewrite, but device
  locale is deliberately **not** honoured — a Romanian app for Timișoara should not flip to English
  because a phone is set to en-US. A manual switch lives in Settings.
- **Diacritics are mandatory** and must be comma-below (ș U+0219, ț U+021B), not cedilla. Ship the
  fonts via `expo-font` and verify glyph coverage on Android, where system fallbacks sometimes lack
  comma-below.
- **Dates** via date-fns with the `ro` locale, 24-hour, Europe/Bucharest. Ladder, in order: today →
  "Azi, 20:00"; tomorrow → "Mâine, 20:00"; within 7 days → "Sâmbătă seara" (seara ≥18:00,
  după-amiază 12–18, dimineața <12); beyond → "14.03.2026, 20:00". Plus the midnight rule above.
- **Prices**: "Gratuit" · "De la 50 RON" · "Până la 120 RON" · "50–120 RON". Romanian number
  formatting (`1.250,50`), RON as a suffix — never "lei", never a symbol.
- **Plurals**: Romanian has **three** forms — 1 / 2–19 / ≥20 with "de": *1 eveniment*,
  *5 evenimente*, *21 **de** evenimente*. Configure the i18next `ro` rule. Getting this wrong is
  instantly obvious to a native speaker.
- No inline string literals — enforce with an ESLint rule.

---

## Analytics

The app is a new activity surface (Plan 1 adds `mobile_feed`, `mobile_browse`,
`mobile_event_detail`, `mobile_saved`). Append `?from=<surface>` to reads and send
`X-Ghes-Client: mobile` as a fallback.

Emit `event_impression`, `event_view`, `event_click` and `search`. **Do not report reactions or
bookmarks** — the server already logs those from their endpoints, and double-reporting inflates the
engagement aggregate that ranks events for everyone.

- **Impressions** via FlashList's `onViewableItemsChanged` with a 60% visibility threshold and a
  1 s minimum view time — a card that flew past during a fling is not an impression. Dedupe per
  event per screen session.
- **Batching**: an MMKV ring buffer, flushed on whichever comes first — 25 events, 30 s, screen
  blur, or app background (flush synchronously there; it is the one moment losing the buffer is
  likely). Each entry carries a client-generated id so a retried batch is idempotent and cannot
  inflate popularity. Drop past 500 entries or 7 days. Analytics never blocks the UI and never
  surfaces an error.
- **Keep the User-Agent clean.** The server's bot filter matches the substrings `preview`,
  `fetcher` and `scanner`; React Native's defaults (`okhttp`, `CFNetwork`) are fine, but a custom UA
  must avoid them or every mobile signal is discarded as bot traffic.

Product analytics stay minimal in v1: Sentry for crashes, and the activity stream doubles as
product analytics. A third-party SDK would only add a data-safety declaration for little value.

---

## Build & release

- **`app.config.ts`** (TS, not static JSON) reading `APP_ENV` → three variants with distinct bundle
  ids so dev / staging / prod coexist on one device: `ro.ghes.app.dev` / `.staging` / `ro.ghes.app`.
  Per-variant name, icon tint, and API base URL.
- **Config**: non-secret values via `EXPO_PUBLIC_*`; everything else as EAS secrets. Anything
  shipped in the JS bundle is public — treat it that way.
- **EAS Build** profiles: `development` (dev client), `preview` (internal distribution / TestFlight,
  staging API), `production`. **EAS Submit** to both stores.
- **OTA via `expo-updates`**, channels `preview` and `production`, `checkAutomatically: ON_LOAD`,
  `fallbackToCacheTimeout: 0` so a cold start never blocks on the network.
  `runtimeVersion: { policy: 'appVersion' }` so an OTA can never land on an incompatible binary.
  **The cost cliff is real:** EAS Update's free tier is small and pricing scales with MAU. Batch
  updates rather than shipping one per commit, and when you outgrow the tier, **self-host the update
  server** — it is open source, and the MAU cap applies to OTA delivery, not installs. Never OTA a
  change that alters what the privacy declaration says, and never use OTA to bypass review for a
  functional change (Apple 3.3.1 permits bug fixes and content, not new features).
- **CI (GitHub Actions)**: typecheck, lint and unit tests on PR; a job that regenerates
  `schema.d.ts` and **fails if the committed file drifts from the published spec** — that is the
  contract with Plan 1; EAS build on merge; a Maestro smoke flow on the preview build.

### Store submission checklist

- **Apple**: App Privacy answers (Contact Info — email/name, linked; Identifiers — device/push
  token; Usage Data — interactions, linked; **no cross-app tracking ⇒ no ATT prompt**);
  export-compliance exemption (HTTPS only); an age rating decided deliberately given nightlife and
  alcohol-adjacent event content; a demo account **with an onboarded profile** plus review notes
  explaining the LLM onboarding; **in-app account deletion** (5.1.1(v), non-negotiable);
  **Sign in with Apple** if Google sign-in ships; privacy policy URL; RO listing.
- **Google**: a Data Safety form matching the Apple answers **exactly** — divergence gets flagged;
  an account-deletion URL *and* the in-app path; target API level; RO listing; a closed testing
  track before production (check the current testing-period requirement before committing to a
  launch date); Play App Signing.
- **Both**: GDPR. The app stores an interest profile **derived from an LLM conversation**; the
  Romanian-language privacy policy must say what is stored, that Claude processes chat content, and
  how to export or delete it.
- Assets: icon, adaptive icon, splash (navy + the kinetic "g"), 6.7"/6.5" screenshots with Romanian
  UI, feature graphic.

---

## Verification

1. `openapi-typescript` regenerates cleanly; `tsc --noEmit` passes; a fixture test asserts the
   `data` + `meta` unwrapping and that **`meta.links` (an array) drives pagination, not `links` (an
   object)** — that exact confusion has already white-screened two pages in the web app.
2. Unit: `formatPrice` including the `price_min: 0 && !is_free → null` case; `formatEventDate`
   including midnight → bare date and the today/tomorrow/weekday-evening ladder; RON formatting; RO
   pluralisation; and a test asserting the category label map covers **all 14** enum values, so it
   fails the day the backend adds one.
3. Auth matrix: fresh install; valid token; expired access + valid refresh (**fire 5 parallel 401s
   and assert exactly one refresh call**); both expired; token revoked server-side; offline cold
   start stays logged in; logout clears SecureStore, MMKV and the push token.
4. Offline (airplane mode): recommendations and saved render from cache; react to 3 events and save
   2; kill and relaunch still offline (paused mutations survived); reconnect → all 5 replay exactly
   once and server state matches.
5. Chat: 10 s simulated latency shows the escalating captions; a forced 500 leaves a retryable
   bubble with the text intact; backgrounding mid-turn and returning recovers via history; the
   keyboard never covers the input on an iPhone SE or a small Android.
6. Push E2E: priming appears after onboarding, not before; the token reaches the server; a real
   digest deep-links to the right event from cold start, background and foreground; lock-screen
   actions register a reaction; revoking the OS permission downgrades the server channel; logout
   stops delivery.
7. Deep links: `npx uri-scheme open ghes://events/{uuid}` in all three app states, authenticated and
   not (unauthenticated should stash and resume after login).
8. Maestro flows: register → onboarding chat → confirm profile → Acasă; browse → filter → open →
   save → verify in Salvate; delete account → relaunch shows Landing.
9. Perf: 200 events scroll at 60 fps on a mid-range Android (blank cells are the FlashList sizing
   smell); cold start to first content <2 s on cached data; track bundle size per release.
10. Localisation: screenshot every screen; grep for non-i18n literals; verify diacritics on Android
    9 and iOS; **no English leaks**, especially on Settings where the web still has them.
11. Accessibility: VoiceOver/TalkBack pass on Acasă and detail; 44 pt minimum targets (the web
    already enforces `min-h-11`); check persimmon-on-white contrast — restrict `#FF5733` text to
    bold ≥16 pt and use navy for body copy.
12. Run on a **physical** iPhone and a **physical** Android, not just simulators, and install an
    `eas build --profile preview` artifact on both.

---

## Milestones

| | Work | Blocked on |
|---|---|---|
| **M0** | Repo, `app.config.ts`, EAS profiles, dev clients, NativeWind + tokens, i18n, ported `lib/*` + tests | — |
| **M1** | `schema.d.ts`, `ky` client + refresh interceptor, AuthProvider, cold-start gate, login/register/Google, Landing | Plan 1 Wave 0 + spec, Google exchange |
| **M2** | EventCard/List, Evenimente, Detaliu, Acasă, Salvate, skeletons, empty states | Plan 1 Wave 0 |
| **M3** | Reactions, saves, optimistic + offline queue, haptics, swipe, share, calendar | Plan 1 C (click endpoint) |
| **M4** | Onboarding chat, confirm-profile card, profile chat, gate end-to-end | Plan 1 C (chat endpoints) |
| **M5** | Profil, settings, notification history, account deletion | Plan 1 B (`DELETE /account`) |
| **M6** | Push + activity batching | Plan 1 D + E |
| **M7** | Sentry, Maestro, localisation sweep, a11y, store assets, submission | — |

---

## Explicitly out of scope for v1

Widgets and Live Activities · Apple/Google Wallet · in-app ticket purchase or payments · social
features (friends, shared plans, comments) · user-submitted events · a real interactive map or
"map browse" mode · a TikTok-style vertical discovery feed (on-brand and tempting, but it is a
second recommendation surface with its own backend needs) · **"Ascunde evenimente ca acesta"** — no
server signal exists, and hiding client-side without one is a lie · offline chat queueing ·
streaming LLM responses · tablet and landscape layouts · dark mode (the brand is navy-on-off-white;
a real dark theme is a design exercise, not a toggle) · Apple Watch · a web build of the RN app
(the Inertia site already covers web) · admin screens · a multi-city switcher (Timișoara until the
backend has more cities).
