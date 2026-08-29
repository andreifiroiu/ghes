# Plan 1 — Backend API for a mobile client

**Repo:** `ghes` (this one) · **Branch from:** `main` · **Companion plan:** `docs/mobile/PLAN_2_EXPO_CLIENT.md`

---

## Context

We are building a native mobile app (iOS + Android) for Ghes. It will be a **thin API client** in
a separate repo, built with **Expo / React Native** (see Plan 2). That makes this repo the
**server of record**.

Unlike a greenfield mobile effort, **the API already exists here** — `routes/api.php` serves 17
Sanctum-authenticated endpoints, five API Resources are in place, and 12 Pest feature tests cover
them. So this plan is about *hardening and completing* an API, not building one. The work is
smaller than it looks, and most of it is independently valuable: a versioned, throttled, contract-
tested API with a real token lifecycle benefits the product whether or not the app ships.

**Phase 1 target: full parity with the web app** (admin excluded) — personalised feed, browse and
search, event detail, reactions and saves, the LLM chat onboarding, the profile-refinement chat,
profile, notification settings, and push.

### Non-goals — do not do these

- **Do not rewrite the web UI.** The Inertia + React frontend stays. React web and React Native
  share no components; a rewrite buys the mobile app nothing.
- **Do not port the admin surfaces.** `admin/*` and `api/admin/*` stay web-only.
- **Do not flip `JsonResource::withoutWrapping()`.** It is set globally at
  `app/Providers/AppServiceProvider.php:40` and every Inertia page prop depends on it. v1 declares
  its own envelope instead (Workstream G).
- **Do not enable Sanctum's stateful/SPA mode.** Nothing on `/api` needs cookie auth, and
  `->statefulApi()` would put CSRF in front of every mobile call.

---

## Current state — what you need to know before starting

| Thing | Where | Status |
|---|---|---|
| Auth | Hand-rolled (no Breeze/Fortify/Jetstream) + Socialite for Google | `config/auth.php:40-50` — `web` (session) and `sanctum` guards |
| Sanctum | `laravel/sanctum ^4.3`; `User` uses `HasApiTokens` (`app/Models/User.php:30`) | Tokens **are** issued — `Api\AuthController.php:39,63` — but with **no abilities, no expiry, no device identity** |
| API routes | `routes/api.php` (45 lines) | register / login / logout, events, recommendations, feedback, bookmarks, profile, notifications, chat history, admin stats |
| API consumers | — | **None besides tests.** No `/api` fetch in `resources/js`, no `route('api.*')` call site anywhere |
| Resources | `app/Http/Resources/` — `UserResource`, `EventResource`, `AdminEventResource`, `AdminUserResource`, `ChatMessageResource` | `withoutWrapping()` is global (`AppServiceProvider.php:40`) |
| Web/API twins | `EventController` (`index`/`apiIndex`, `show`/`apiShow`), `RecommendationController`, `BookmarkController`, `ChatController` | The established convention. `EventController::detailProps()` exists *precisely* so the two paths cannot drift — copy that pattern |
| Push | `app/Services/Notification/PushSender.php` (Web Push, VAPID, `minishlink/web-push`), `PushSubscription` model, `public/sw.js` | Web only. No native channel |
| Digest send | `NotificationDispatcher::dispatch()` → `Mail::html()` + `PushSender::sendToUser()` | Driven by `eventpulse:send-notifications`, scheduled daily in `routes/console.php:23` |
| Activity | `ActivityLogger`, `ActivityType`, `ActivitySurface`, `ProcessActivitySignalJob`, `eventpulse:aggregate-engagement` | Impressions/clicks feed `events.engagement_score` |
| Tests | Pest 4; `tests/Pest.php` applies `RefreshDatabase` to Feature only | 12 files under `tests/Feature/Api/` |

### ⚠️ Critical gotchas

**1. There is no global API throttle.** `bootstrap/app.php` never calls `->throttleApi()`, and the
framework only adds `throttle:` to the `api` group when an `apiLimiter` is configured. Confirmed
with `route:list --json`: `GET api/events` carries only `api, Authenticate:sanctum`. **Every
authenticated API route is currently unlimited.** Only `auth/register` (`throttle:10,1`) and
`auth/login` (`throttle:5,1`) have inline limits.

Related: the only `RateLimiter::for` in the app is `anthropic-api`
(`app/Providers/AppServiceProvider.php:52`) and **nothing references it**. Wire it or delete it —
an unreferenced limiter sitting next to live ones is how the next person assumes it works.

**2. `route('verification.verify')` does not exist — two web paths 500 today.**
`User` implements `MustVerifyEmail`, and `app/Http/Controllers/ProfileController.php:37,46` calls
`sendEmailVerificationNotification()`. Laravel's `VerifyEmail` notification builds its URL from
`route('verification.verify')`, which is registered nowhere. Confirmed:

```
$ php artisan tinker --execute 'echo route("verification.verify", ["id"=>1,"hash"=>"x"]);'
Symfony\Component\Routing\Exception\RouteNotFoundException: Route [verification.verify] not defined.
```

So `POST /profile/resend-verification` and any profile email change throw a 500. No test covers
it. **This is a live web bug**, not merely a mobile gap.

**3. There are no password-reset routes at all.** The `password_reset_tokens` table exists
(`0001_01_01_000000_create_users_table.php:33`), but no `password.request` / `password.reset`
routes. A mobile app with email/password sign-in cannot ship without them.

**4. There is no self-service account deletion.** Only `Admin\UserController::destroy`. Apple
guideline 5.1.1(v) requires in-app deletion for any app that allows account creation.

**5. A mobile click would silently not train the recommender.**
`ActivityController::redirect` dispatches `ProcessActivitySignalJob` only when there is a
**session** user. A native `Linking.openURL(click_url)` carries no cookie and no bearer token, so
the click is logged but never reaches `FeedbackProcessor`. Nothing errors; recommendations just
quietly stop learning from the app's most common action. This is why Workstream C adds an
authenticated click endpoint rather than reusing `go/{event}`.

**6. Response shapes are already inconsistent.** `apiIndex` returns a paginator envelope,
`apiShow` returns `{data, relatedEvents}`, `/api/profile` returns a **bare** object (no `data`),
`/api/recommendations` returns `{recommendations, discovery, total_score}`, `/api/notifications`
returns `{data, total}` with raw Carbon timestamps. camelCase (`onboardingComplete`, `redirectTo`)
mixes with snake_case (`hit_rate`). One client, several parsers.

**7. `config('eventpulse.pagination.events')` is 18, not 20.** Any client that hardcodes 20
mis-sizes its skeletons.

---

## Workstream A — Versioning & routing

**Blocks everything. ~0.5 day.**

### Decision: a clean cut to `/api/v1/`, with no compatibility alias.

Because the existing API has **no consumers besides tests** (verified), this is nearly free — and
it is the only moment we can fix the envelope inconsistencies in gotcha 6 without shipping a
breaking change later.

Rejected alternatives:

- **Keep `/api/*` unversioned.** The shapes have to change for the client to have one parsing
  rule, so the contract's first published version would already be a breaking change waiting to
  happen, with no lever to ship v2 without a flag day.
- **Dual-mount `/api/*` as an alias of `/api/v1/*`.** Doubles the surface that the ability and
  throttle middleware must cover, and a forgotten alias is exactly how the hardening gets
  bypassed. There is nothing to be compatible with.
- **A separate `routes/api/mobile.php`.** Phase 1 is full parity, so a "mobile" file would hold
  ~95% of the API and fork the contract on day one. **Scope by token ability, not by route file.**

### Changes

1. **Create `routes/api/v1.php`** — the current `routes/api.php` body moves here with relative
   names (`auth.login`, `events.index`, …).
2. **Rewrite `routes/api.php`** as a loader:
   ```php
   Route::prefix('v1')->name('api.v1.')->group(base_path('routes/api/v1.php'));

   // An old build in the wild should report "upgrade", not "server broken".
   Route::any('{path}', ApiVersionGoneController::class)->where('path', '.*');
   ```
   The catch-all lives inside the `api` prefix, so it cannot shadow `/up`, Horizon, or Log Viewer
   (all on `web`).
3. **`bootstrap/app.php`**:
   - add `->throttleApi()` — this is the fix for gotcha 1;
   - register Sanctum's ability middleware aliases (Laravel 11+ does not auto-register them):
     ```php
     $middleware->alias([
         'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
         'ability'   => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
     ]);
     ```
   - do **not** add `->statefulApi()`.

### Migration cost

- The 12 files under `tests/Feature/Api/` use `/api/...` literals — mechanical rewrite to
  `/api/v1/...`. `ApiAuthTest.php` and `ApiEndpointsTest.php` carry most of them.
- Route names change `api.*` → `api.v1.*`. Zero call sites (verified).
- `->throttleApi()` newly limits routes the tests hammer. Define the `api` limiter at 120/min;
  `phpunit.xml` sets `CACHE_STORE=array`, so limiter state resets between tests and only a single
  test looping >120 requests could trip.

---

## Workstream B — Auth hardening

**~2 days.** B4 and B6 are independently parallelisable.

### B1 — Named limiters

In `app/Providers/AppServiceProvider::boot()`:

| Limiter | Limit | Keyed by |
|---|---|---|
| `api` | 120/min | token id ?? IP |
| `api-auth` | 5/min | `email\|ip` |
| `api-register` | 10/hour | IP |
| `api-refresh` | 30/min | device id ?? IP |
| `api-chat` | 20/min + 200/day | user id — **LLM calls cost money** |
| `api-devices` | 30/min | user id |
| `api-verify` | 6/min | user id |

Replace the inline `throttle:10,1` / `throttle:5,1` on register/login with the named limiters, and
resolve the dead `anthropic-api` limiter while you are in the file.

### B2 — Token model

New `app/Enums/TokenAbility.php`:

```php
case AccessApi     = 'api:access';
case RefreshToken  = 'token:refresh';
case Admin         = 'admin';   // granted only when Gate::allows('access-admin')
```

Migration `xxxx_add_device_columns_to_personal_access_tokens_table.php`: nullable `device_id`
(uuid, indexed), `device_name`, `platform`, `app_version`.

- **Access token** — abilities `[api:access]` (+`admin`), `expires_at = now()->addMinutes(config('eventpulse.api.tokens.access_ttl_minutes', 60))`.
- **Refresh token** — ability `[token:refresh]` **only**, `expires_at = now()->addDays(config('eventpulse.api.tokens.refresh_ttl_days', 60))`, same `device_id`.

Separate abilities are the point: a stolen access token cannot mint new ones.

> **Landmine — `config/sanctum.php:53` `'expiration' => null` must stay null.** Sanctum's global
> `expiration` *overrides* per-token `expires_at`. Setting it to 60 would silently expire refresh
> tokens after an hour and lock every user out on day one.

Extract issuance into `app/Services/Auth/TokenIssuer.php` (`issuePair(User, DeviceContext): TokenPair`)
so register / login / oauth / refresh cannot drift.

### B3 — Endpoints

`app/Http/Controllers/Api/V1/AuthController.php`, refactored from the current one.

| Method | Path | Notes |
|---|---|---|
| POST | `auth/register` | + required `device_name`, `platform`; returns a token pair |
| POST | `auth/login` | + `device_name`, `platform` |
| POST | `auth/refresh` | `auth:sanctum` + `ability:token:refresh`; rotates the pair, revokes both old tokens by `device_id` |
| POST | `auth/logout` | revokes the pair for the current `device_id`, deletes that device's push row |
| POST | `auth/logout-all` | `$user->tokens()->delete()` + delete all `devices` rows |
| GET | `auth/sessions` | `device_id` / `device_name` / `last_used_at` — cheap, and reviewers like it |
| POST | `auth/oauth/google` | native ID-token exchange — see B4 |
| POST | `auth/password/forgot` | `Password::sendResetLink` |
| POST | `auth/password/reset` | `Password::reset` |
| POST | `auth/email/verification-notification` | `auth:sanctum`, `throttle:api-verify` |
| DELETE | `account` | `auth:sanctum` + `current_password` — see B5 |

A replayed (already-rotated) refresh token simply 401s, because the row is gone. Full OAuth-style
**reuse detection** — revoking the whole token family on replay — needs retained rotated-token
rows and is not worth it in Phase 1. Note the upgrade path in the spec and move on.

### B4 — Google sign-in from a native app

Socialite's `redirect()` / `user()` is a stateful web dance: the `state` parameter lives in a
session the app does not carry. Two native options:

- **(A) `expo-auth-session` PKCE against Google directly → the app sends the returned `id_token` →
  we verify it server-side.** ✅
- (B) PKCE against our own `/auth/{provider}/redirect` with a custom scheme. ❌ — Socialite still
  wants the session, and it means maintaining our own authorisation-server semantics.

**Pick A.** New `app/Services/Auth/GoogleIdTokenVerifier.php`:

- `Http::get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $token])`
- assert `iss ∈ {accounts.google.com, https://accounts.google.com}`, `aud ∈ config('services.google.client_ids')`
  (iOS / Android / web client ids), `exp > now`, and **`email_verified === 'true'`**.

Trade-off vs. local JWKS verification: one network round-trip per sign-in, in exchange for ~30
lines instead of a JWT/JWKS implementation, and a trivially `Http::fake()`-able seam. Sign-in is
not a hot path; revisit if it ever becomes one.

Extract the find-or-create branch out of `Auth\OAuthController::callback()` into
`app/Services/Auth/SocialAccountLinker.php` so web and native share one identity rule
(`email_verified_at => now()`, `onboarding_completed => false`, random password).
`tests/Feature/Auth/OAuthTest.php` guards the web side.

> **Security note, worth writing down in the PR:** the current web callback links purely by email
> address, with no stored provider record. Requiring `email_verified` on the ID token is what stops
> an unverified Google address from taking over an existing password account. This is a deliberate
> behaviour change to the **web** flow, not only an addition to the native one.

### B5 — Account deletion

`app/Services/Account/AccountDeleter.php::delete(User $user): void`, in a transaction, in this
order:

1. `$user->tokens()->delete()` — morph relation, **no cascade**;
2. `DB::table('sessions')->where('user_id', $user->id)->delete()` — `sessions.user_id` is a
   `foreignUuid(...)->index()` with **no `constrained()`**, so there is no FK and no cascade;
3. `$user->devices()->delete()` and `$user->pushSubscriptions()->delete()`;
4. `$user->delete()` — `event_notifications`, `user_event_reactions`, `event_bookmarks`,
   `chat_messages`, `discovery_logs`, `user_activity_logs` all cascade.

Immediate hard delete, no grace period — Apple accepts it, and a grace period needs a whole
reconsent flow. State the consequence: the user's `user_activity_logs` rows go with them, so
historical engagement *counts* shift slightly, but `events.engagement_score` is a persisted
aggregate that survives, so ranking does not forget.

### B6 — Fix the two pre-existing bugs (gotchas 2 and 3)

In `routes/web.php`:

- `GET verify-email/{id}/{hash}` named **`verification.verify`**, middleware `['signed','throttle:6,1']`,
  handled by a new `app/Http/Controllers/Auth/VerifyEmailController.php`. Mark verified, then
  redirect to `config('eventpulse.mobile.scheme').'://verified'` when the signed URL carries
  `intent=mobile`, else to `route('profile.show')` with a flash.
- `GET reset-password/{token}` named **`password.reset`** and `POST reset-password` — the
  `ResetPassword` notification requires the named route to exist.

The app confirms verification by polling `GET api/v1/profile` for `email_verified_at`; the deep
link is a nicety, not the mechanism.

---

## Workstream C — Closing the parity gaps

**~1.5 days.** Every row reuses an existing controller or service.

Everything already in `routes/api.php` — events index/show, `events/saved`, recommendations and
history, feedback, bookmarks, profile, `profile/stats`, notifications, `chat/history` — **carries
over into v1 unchanged apart from the envelope** (Workstream G). The table below is only the gaps.

| Web-only surface | New API route | Method | Payload |
|---|---|---|---|
| `GET onboarding` | `GET api/v1/onboarding` | `ChatController::apiOnboarding` | `{data:{messages:[…], onboarding_complete}}` |
| `POST onboarding/chat` | `POST api/v1/onboarding/chat` | `ChatController::store` (**reuse as-is**) | already JSON |
| `POST onboarding/confirm-profile` | `POST api/v1/onboarding/confirm` | `ChatController::confirmProfile` | drop `redirectTo` |
| `GET profile/chat` | `GET api/v1/profile/chat` | `ChatController::apiProfileChat` | messages |
| `POST profile/chat` | `POST api/v1/profile/chat` | `ChatController::profileChatStore` (reuse) | — |
| `POST profile/chat/apply` | `POST api/v1/profile/chat/apply` | `ChatController::applyProfileUpdate` | drop `redirectTo` |
| `GET settings/notifications` | `GET api/v1/settings/notifications` | `NotificationSettingsController::apiShow` | `{data: UserResource, channels, frequencies}` — **no `vapidPublicKey`**, native does not use VAPID |
| `PUT settings/notifications` | `PUT api/v1/settings/notifications` | `apiUpdate` | reuse `NotificationSettingsRequest`; return `UserResource`, not a redirect |
| `POST profile/resend-verification` | `POST api/v1/auth/email/verification-notification` | B6 | — |
| `POST\|DELETE push/subscribe` | superseded by `devices` | Workstream D | — |
| `GET events/{event}/calendar.ics` | **no new endpoint** | — | add `ics_url` to `EventResource` |
| `GET go/{event}` | `POST api/v1/events/{event}/click` | new `Api\V1\EventClickController` | `{data:{url}}` |

### Two anti-drift extractions

Both follow the precedent set by `EventController::detailProps()`, whose docblock says outright
that it exists "so a change to one cannot silently leave the other behind".

**`app/Services/Chat/ChatThread.php`** — `messagesFor(User $user, string $context): Collection`,
seeding the welcome message. `ChatController::index()` and `profileChat()` each seed inline today;
without this, mobile users get an empty first screen the day someone edits the web seeding. Called
from all four methods (2 web, 2 API).

**`app/Services/Activity/ClickDestinationResolver.php`** — extracted from
`ActivityController::destinationFor()`. That method is the open-redirect guarantee: the destination
is always the event's own stored URL, and `?s=` only *selects* among them. It must exist exactly
once. `tests/Feature/Activity/ClickTrackingTest.php` guards the web caller.

### The click endpoint (gotcha 5)

The naive option — have the client `Linking.openURL(EventResource.click_url)` and let the 302 do
its job — works, needs no backend, and is wrong for the reason in gotcha 5. Instead
`POST api/v1/events/{event}/click`, with an optional `source`:

1. resolve canonical, `abort_if($event->is_hidden, 404)`;
2. resolve the destination via `ClickDestinationResolver` — the same logic as the web redirector;
3. `ActivityLogger::log(EventClick, <mobile surface>, …)`;
4. `ProcessActivitySignalJob::dispatch(...)` when `! $log->is_bot`;
5. return `{data:{url}}` for `expo-web-browser`.

### `GET api/v1/meta`

New, auth-optional: categories from `EventCategory::cases()`, cities from
`config('eventpulse.cities')`, ranges (`weekend`), `page_size` from
`config('eventpulse.pagination.events')` (**18**), `NotificationChannel` / `NotificationFrequency`
cases, and `min_supported_app_version`.

This kills the whole "the chip sent `Tech` but the enum is backed by `technology`" bug class
already recorded in `.claude/ship-it.md`, and gives a force-upgrade lever that cannot be
retrofitted once v1.0 is in the stores.

### Smaller items

- **Saved events**: `BookmarkController::apiIndex` returns an unpaginated collection. Paginate it
  in v1 — fresh contract, no cost. `BookmarkService::savedEventsFor()` gains a paginating twin.
- **Search/filter parity is already done.** `apiIndex` shares `browseQuery()`, so
  `search|category|city|date|range` behave identically, including the Scout/Meilisearch path and
  the exclusion of `not_interested` events. Document in the spec that `search` requires
  Meilisearch — tests run `SCOUT_DRIVER=null`, so a v1 search test asserts wiring, not relevance.
- **Reaction vocabulary, for the client doc:** `Reaction` has only `Interested` /
  `NotInterested`. "Saved" is `POST bookmarks`. **"Hidden" *is* `not_interested`** —
  `browseQuery()` already excludes those event ids. There is no separate hide endpoint and there
  should not be one.

---

## Workstream D — Push for native

**~1.5 days.**

### Decision: Expo Push Service, behind an interface.

**Why Expo over direct FCM v1 / APNs:** one HTTPS endpoint and one credential, instead of an APNs
`.p8` (with key/team ids and rotation) *plus* a Google service account living in the Laravel repo.
`expo-notifications` yields a token in two client lines. And the receipts API returns
`DeviceNotRegistered` **explicitly**, which is exactly the pruning signal — with FCM you infer it
from `UNREGISTERED`, with APNs from a 410 plus a timestamp. It also works in Expo Go and dev
builds, so the client repo is not blocked on native credentials.

**Costs, accepted:** a third party in the delivery path, 100-message batches, receipts arriving
~15 minutes later. At one digest per user per day for a Timișoara-first user base, this is not
close. **Mitigation for lock-in:** define `app/Contracts/PushChannel.php` — the repo already uses
`app/Contracts/` for `ScraperAdapter` — so swapping to FCM v1 later is a new class, not surgery on
the dispatcher.

### Schema — `xxxx_create_devices_table.php`

```
uuid id primary
foreignUuid user_id constrained cascadeOnDelete, index
string platform                  // App\Enums\DevicePlatform: ios|android|web
string push_token, 255
uuid   install_id nullable, index
string device_name  nullable
string app_version  nullable
string os_version   nullable
string locale       nullable
string timezone     nullable
timestamp last_seen_at nullable
timestamps
unique('push_token')
index(['user_id', 'platform'])
```

> **The unique key is `push_token` alone, not `(user_id, push_token)`.** When user B signs in on
> user A's phone, Expo returns the *same* token. A composite unique leaves A's row alive and A's
> digest lands on B's lock screen. `updateOrCreate(['push_token' => …], ['user_id' => …])`
> re-points it. This is the bug that otherwise ships and gets reported as "I'm getting someone
> else's notifications".

> `push_token` at 255: Expo tokens are ~40 chars, but FCM tokens exceed 160, and this repo already
> carries a `widen_long_url_columns` migration. Do not learn that twice.

Also add a nullable `install_id` (uuid) to `push_subscriptions` — see the dedup rule below.

New: `app/Models/Device.php` (HasUuids, `belongsTo(User)`), `app/Enums/DevicePlatform.php`,
`User::devices(): HasMany`.

### Endpoints

- `POST api/v1/devices` — idempotent register/refresh, `throttle:api-devices`, validated by
  `app/Http/Requests/DeviceRegistrationRequest.php` (`push_token` matching
  `^Expo(nent)?PushToken\[.+\]$` while the Expo driver is active, `Rule::enum(DevicePlatform::class)`,
  semver `app_version`). Touches `last_seen_at`.
- `DELETE api/v1/devices` — by `push_token`, called on sign-out.
- `GET api/v1/devices` — optional, pairs with `auth/sessions`.

Leave `POST|DELETE push/subscribe` on `routes/web.php` untouched — the web page uses it, and
`public/sw.js` needs **no change**.

### Fan-out — `app/Services/Notification/PushFanout.php`

`NotificationDispatcher.php:57-65` becomes one call:

```php
$this->pushFanout->sendToUser($user, PushPayload::digest($notification, $subject, $eventCount));
```

**The dedup rule, stated plainly:** a web-push subscription and an Expo device are *different
physical delivery targets*, so sending to both is not a duplicate — someone with the site on their
laptop and the app on their phone should get both. The only true duplicate is **the same handset
registered twice** (browser + native app), and there is no join key between
`push_subscriptions.endpoint` and `devices.push_token`.

Fix: both clients send a persisted `install_id` (a UUID generated once and stored). At fan-out,
suppress any `push_subscriptions` row whose `install_id` matches a `devices.install_id` for that
user. Legacy subscriptions have `install_id = null` and are never suppressed — correct, since a
desktop browser is a different device anyway.

`PushFanout` returns `PushFanoutResult{web:int, expo:int, suppressed:int}` for the dispatcher's
existing `Log::info`.

> **`ExpoPushSender` must never throw.** `NotificationDispatcher::dispatch()` sets `sent_at`
> *after* the push branch and guards re-entry on `sent_at !== null`. `PushSender` swallows
> everything today, so mail is never sent twice on a job retry. An `ExpoPushSender` that lets an
> HTTP exception escape converts that latent hazard into a real duplicate-digest-email bug. Log and
> return, exactly like `ActivityLogger` does.

### Payload

`app/Services/Notification/PushPayload.php` (value object) + `app/Enums/PushPayloadType.php`
(`digest|event|profile`), rendered once and adapted per channel — web push reads `payload.url`
(see `public/sw.js`), Expo reads `data`:

```json
{
  "to": "ExponentPushToken[xxx]",
  "title": "Digestul tău Ghes",
  "body": "Ai 7 evenimente noi recomandate pentru tine.",
  "sound": "default",
  "channelId": "digest",
  "priority": "normal",
  "data": {
    "type": "digest",
    "deep_link": "ghes://digest/9f1c…",
    "notification_id": "9f1c…",
    "event_id": null
  }
}
```

Config: `eventpulse.mobile.scheme` (default `ghes`), `eventpulse.mobile.universal_link_host`. The
dispatcher currently hardcodes `route('dashboard')`; that becomes `PushPayload`'s web `url`, with
the deep link derived from the same object so the two channels cannot say different things.

### Pruning

- **Inline (tickets)** — Expo returns per-message tickets synchronously; `status: "error"` with
  `details.error: "DeviceNotRegistered"` → delete the device. Mirrors `PushSender`'s
  `isSubscriptionExpired()` prune.
- **Deferred (receipts)** — `app/Jobs/FetchExpoPushReceiptsJob.php` on the `notifications` queue,
  `->delay(now()->addMinutes(20))`, carrying the ticket ids in its payload (bounded by the
  100-message batch, so no `push_tickets` table is needed in Phase 1). Deletes on
  `DeviceNotRegistered`; logs `MessageTooBig` / `MessageRateExceeded`.
- **Stale** — `app/Jobs/PruneStaleDevicesJob.php`, scheduled weekly in `routes/console.php` beside
  `PruneActivityLogsJob`, deleting rows with `last_seen_at < now()->subDays(config('eventpulse.push.expo.stale_device_days', 120))`.

### Config

Extend the existing `push` block in `config/eventpulse.php:178`:

```php
'expo' => [
    'enabled'           => (bool) env('EXPO_PUSH_ENABLED', false),
    'endpoint'          => env('EXPO_PUSH_ENDPOINT', 'https://exp.host/--/api/v2/push/send'),
    'receipts_endpoint' => env('EXPO_PUSH_RECEIPTS_ENDPOINT', 'https://exp.host/--/api/v2/push/getReceipts'),
    'access_token'      => env('EXPO_ACCESS_TOKEN'),
    'batch_size'        => 100,
    'stale_device_days' => 120,
],
```

Plus `.env.example` entries. Never `env()` outside config files.

---

## Workstream E — Activity surfaces

**~0.5 day. Do it in the same pass as D, to avoid touching the same controllers twice.**

Add to `app/Enums/ActivitySurface.php`:

```php
case MobileFeed        = 'mobile_feed';
case MobileBrowse      = 'mobile_browse';
case MobileEventDetail = 'mobile_event_detail';
case MobileSaved       = 'mobile_saved';
```

`Api` stays, now meaning "an API caller that did not identify a screen".

**Per-screen, not one `mobile` case.** The enum's own docblock says surfaces exist because "the
difference is what tells us which surface is worth investing in". A single `mobile` case makes
mobile the one platform whose funnel you cannot read, and the distinction cannot be backfilled
once the rows are written.

**How the server learns the surface** — two mechanisms, use both:

1. `ActivitySurface::fromRequest($request->query('from'))` **already exists** and is already how
   the digest identifies itself. The client appends `?from=mobile_feed`. Zero new machinery.
2. `app/Http/Middleware/ResolveClientSurface.php` on the v1 group, stashing a *default* surface
   derived from an `X-Ghes-Client: mobile` header, so a client that forgets `from=` still lands on
   `mobile_*` rather than the ambiguous `api`.

Then change three hardcoded `ActivitySurface::Api` call sites to read that default:
`EventController::apiIndex`, `EventController::apiShow` (via `detailProps`), and
`RecommendationController::apiIndex`.

Note the asymmetry in `detailProps()`: the `?from=` override currently applies only when the
surface is `EventDetail`. Extend it to the API path too — otherwise a mobile detail view opened
from a push notification cannot be attributed to push.

Nothing in `EngagementAggregator`, `eventpulse:aggregate-engagement` or `PruneActivityLogsJob`
needs changing — they group by `type`, not `surface`. Do check that
`resources/js/Pages/Admin/Analytics.jsx` does not switch on a closed list of surfaces.

**Two notes for the client doc:**

- `RequestFingerprint::botReason()` has an explicit carve-out for an authenticated request with no
  User-Agent, so the app will not be flagged as a bot. But
  `config('eventpulse.activity.bot_user_agents')` contains `preview`, `fetcher` and `scanner` — the
  client's UA must avoid those substrings. React Native's defaults (`okhttp/4.x` on Android,
  `CFNetwork` on iOS) are clean.
- `RequestFingerprint::sessionKey()` returns null for API requests (no session), so any report
  counting distinct `session_key` reads mobile as one anonymous blob. Acceptable in Phase 1 —
  mobile is always authenticated — but worth stating.

---

## Workstream F — API contract

**~1 day.**

### Where: `openapi/v1.yaml` at the repo root, hand-maintained.

**Rejected — an annotation/inference generator (Scramble et al.).** Every controller here returns
a bare `JsonResponse` (`response()->json([...])`), so inference produces an empty schema for most
of the surface. Adding a package to a repo with no CI, where "5 pre-existing PHPStan errors" is the
bar, is a bigger commitment than the contract is worth.

**Rejected — `docs/`.** `.claude/ship-it.md` records "no `CHANGELOG.md`, no `docs/`" as a
deliberate state, and this plan document is already the exception. `openapi/` is a directory whose
only possible content is the contract.

### The tests that keep it honest

`symfony/yaml` is already vendored — no new dependency.

1. **`tests/Feature/Api/OpenApiContractTest.php` — route ↔ spec bijection.** Enumerate
   `Route::getRoutes()` where the URI starts with `api/v1` and is not under `api/v1/admin`; assert
   every `(method, path)` appears in the spec, and every spec path resolves to a real route, with
   path params normalised via the route's own parameter names. **This is the test that actually
   catches drift** — adding an endpoint without a spec entry goes red.
2. **Shape spot-checks** for the two resources the client leans on hardest: hit `GET api/v1/events`
   and `GET api/v1/profile` with `Sanctum::actingAs`, and diff the top-level key sets against the
   spec's `properties`. A hand-rolled key diff; no JSON-schema package.
3. **`tests/Feature/Api/ApiRouteGuardsTest.php`** — every `api/v1` route outside the public auth
   set carries both `auth:sanctum` and a `throttle:`, and every `api/v1/admin` route carries
   `can:access-admin`. **Write this in Wave 0, before the endpoints exist.** It is the durable
   defence against gotcha 1 silently reopening.

Plan 2 runs `openapi-typescript` against this file to generate its API types.

---

## Workstream G — Envelope, pagination, errors

**~1 day, Wave 0. Mostly mechanical, but it is the contract the client is built on.**

### Do not flip `withoutWrapping()`

`AppServiceProvider.php:40` sets it globally and every Inertia prop depends on it. v1 declares its
envelope explicitly instead.

### The rules the client can rely on

- **Paginated list** → `{ "data": [...], "links": {...}, "meta": {...} }` — the shape
  `Resource::collection($paginator)` already emits. **`links` is an object
  (`first`/`last`/`prev`/`next`); the page links live at `meta.links` as an array.** This exact
  confusion white-screened two admin pages already (`.claude/ship-it.md`). It must be stated in the
  client plan, not merely in the spec.
- **Single resource** → `{ "data": { ... } }`. Today `GET /api/profile` returns the user **bare**
  while the list returns `data` — one client, two parsers. New `app/Http/Responses/ApiResponse.php`
  (`::item()`, `::collection()`, `::paginated()`, `::message()`), used by every v1 controller
  method. That helper is the only realistic way to keep ~30 endpoints consistent without a global
  flag flip.
- **Keys:** snake_case everywhere. `onboardingComplete` → `onboarding_complete`. **Drop
  `redirectTo` entirely** — a server-chosen web URL is meaningless to a native router; the client
  decides where to go.
- **Timestamps:** ISO-8601 UTC via `toIso8601String()`. `UserResource::email_verified_at` and
  `Api\NotificationController` currently emit raw Carbons — normalise both.
- **Idempotency:** `POST feedback`, `POST bookmarks` and `POST devices` are all
  `updateOrCreate`-backed and safe to retry. Say so in the spec, so the client can retry blindly on
  a network blip.

### Error shape

`bootstrap/app.php`'s `withExceptions()` is currently empty. Add a renderable producing:

```json
{ "error": { "code": "validation_failed", "message": "…", "details": { "email": ["…"] } } }
```

`code` from a new `app/Enums/ApiErrorCode.php` (`validation_failed`, `unauthenticated`,
`token_expired`, `forbidden`, `not_found`, `rate_limited`, `upgrade_required`, `server_error`).
429 also carries `retry_after`.

> **This is the highest-risk edit in the plan. Scope the renderable to `$request->is('api/v1/*')`
> and return `null` otherwise.** The obvious-looking `$request->expectsJson()` guard would also
> capture `FeedbackController`, `BookmarkController` and `ChatController`, which return JSON **to
> the web frontend** — and would reshape responses `resources/js` already parses.

### Force-upgrade lever

`app/Http/Middleware/EnforceMinimumAppVersion.php` on the v1 group: reads `X-Ghes-App-Version`,
returns 426 with `{error:{code:'upgrade_required'}}` below
`config('eventpulse.mobile.min_supported_version')`. An absent header passes, so tests and curl are
unaffected. Cheap now; impossible to retrofit once v1.0 is in the stores.

---

## Sequencing

```
Wave 0    A (versioning) + G (envelope/errors) + GET meta + the guard test   ← hard blocker, 1.5 d
Wave 0.5  Write openapi/v1.yaml, contract-first                              ← unblocks Plan 2, 0.5 d
Wave 1    B (auth)  ∥  B6 (verify + password reset)  ∥  C (parity endpoints)   2 d / 0.5 d / 1.5 d
Wave 2    D (push)  +  E (surfaces, same pass)                                1.5 d + 0.5 d
Wave 3    F (spec reconciliation + contract test)                             0.5 d
```

**~8 days solo, 4–5 with two people.**

The highest-leverage decision is Wave 0.5: **write the OpenAPI spec before implementing C and D**,
so the Expo repo starts against a mock on day two rather than day eight. Plan 2's client work can
begin as soon as Wave 0 + the spec exist; push (D) is not a blocker for it.

---

## Testing plan

Pest 4. `tests/Pest.php` applies `RefreshDatabase` to Feature only;
`beforeEach(fn () => $this->withoutVite())` for anything rendering Inertia. Copy
`tests/Feature/Api/EventsIndexTest.php` for API assertions and `tests/Feature/Admin/AdminEventTest.php`
for the `config(['eventpulse.admin_emails' => …])` idiom.

- **A** — `ApiVersioningTest`: `/api/v1/events` 200; legacy `/api/events` 410; the catch-all does
  not shadow `/up`. Plus the mechanical path rewrite across the 12 existing `tests/Feature/Api/*`
  files.
- **B** — `ApiAuthTest` extended: register/login return an access **and** a refresh token with the
  right abilities and `expires_at`; the access token is rejected on `auth/refresh` (wrong ability);
  refresh rotates and revokes the old pair; a replayed refresh 401s; `logout` kills only that
  `device_id`; `logout-all` kills every token **and** every device.
  New `GoogleSignInTest` with `Http::fake()` on `oauth2.googleapis.com/tokeninfo` — happy path,
  wrong `aud`, `email_verified=false`, expired.
  New `AccountDeletionTest` — no orphan left in any child table, tokens and sessions gone, wrong
  password 422.
  New `tests/Feature/Auth/EmailVerificationTest.php` — **must include a regression test that
  `POST profile/resend-verification` does not 500** (gotcha 2). New `PasswordResetTest`.
  New `ApiThrottleTest` — the login limiter blocks the 6th attempt; an authenticated route carries
  the `api` limiter.
- **C** — `ChatApiTest` (reuse the `fakeOnboardingClaude()` helper already in
  `tests/Feature/Api/OnboardingTest.php`); `NotificationSettingsApiTest`; `EventClickApiTest` —
  asserts a `user_activity_logs` row with `type=event_click`, that the returned `url` is the
  event's own stored URL, that **an unknown `source` falls back rather than redirecting anywhere
  the caller named** (the open-redirect guarantee), and that `ProcessActivitySignalJob` is
  dispatched (`Queue::fake()`; note `QUEUE_CONNECTION=sync` in `phpunit.xml`).
  Extend `tests/Feature/Activity/ClickTrackingTest.php` to prove the web redirector still behaves
  after the `ClickDestinationResolver` extraction. `MetaEndpointTest` asserts `page_size` equals
  `config('eventpulse.pagination.events')` (**18**).
- **D** — `DeviceRegistrationTest`: registration is idempotent; **re-registering the same
  `push_token` under a second user re-points the row and leaves none for the first** (the important
  one); an invalid Expo token 422s.
  `PushFanoutTest` with `Http::fake()` on `exp.host`: web-only user → web only; native-only → Expo
  only; both → both; matching `install_id` → the web subscription is suppressed; a
  `DeviceNotRegistered` ticket deletes the device; **an HTTP failure does not throw and does not
  prevent `sent_at` being set** (the double-email hazard). Extend the send-notifications command
  test for the dispatcher seam.
- **E** — `MobileSurfaceTest`: `GET api/v1/events?from=mobile_browse` writes impressions with
  `surface=mobile_browse`; no `from` plus `X-Ghes-Client: mobile` also writes `mobile_browse`;
  neither writes `api`. Assert `is_bot=false` for an authenticated API call with no User-Agent,
  locking in the `RequestFingerprint` carve-out.
- **F** — `OpenApiContractTest` and `ApiRouteGuardsTest` as described above.
- **G** — `ApiEnvelopeTest`: every v1 single-resource response has a top-level `data`; a paginated
  one has `data` + `links` + `meta` with `meta.links` an array; 422 / 401 / 404 / 429 each match
  the error shape; **and the web-facing `POST /feedback` JSON is byte-for-byte unchanged** — the
  regression the scoped renderable is protecting.

**Gates, per `.claude/ship-it.md`:** `vendor/bin/pint --dirty --format agent` (never repo-wide — 14
files pre-fail), `vendor/bin/phpstan analyse --memory-limit=1G` (level 6; the bar is **still 5
errors** — diff the error *sets*, not the count), `php artisan test --compact`.

---

## Risk register

| # | Risk | Safety net |
|---|---|---|
| 1 | `withExceptions()` renderable scoped by `expectsJson()` would reshape the JSON the **web** frontend parses | Scope to `$request->is('api/v1/*')`; `ApiEnvelopeTest` asserts `POST /feedback` is unchanged |
| 2 | Setting `config/sanctum.php` `expiration` would override per-token `expires_at` and kill refresh tokens | Leave it `null`; `ApiAuthTest` asserts the refresh token's `expires_at` |
| 3 | `->throttleApi()` newly limits every API route | `api` limiter at 120/min; `CACHE_STORE=array` resets per test |
| 4 | `ApiResponse` wrapping breaks `ApiEndpointsTest` (`assertJsonPath('id', …)` → `data.id`) and reshapes `/events/{id}` | Expected — enumerate the changed assertions in the PR body |
| 5 | Extracting `destinationFor()` touches a public, unauthenticated redirector | `tests/Feature/Activity/ClickTrackingTest.php`; do not weaken "the destination never comes from the request" |
| 6 | Extracting `SocialAccountLinker` + requiring `email_verified` changes the **web** OAuth flow | `tests/Feature/Auth/OAuthTest.php`; call the change out explicitly |
| 7 | `ExpoPushSender` throwing turns a latent retry hazard into duplicate digest emails | `PushFanoutTest` asserts an HTTP failure neither throws nor blocks `sent_at` |
| 8 | The sqlite/Postgres split — a JSON-column predicate passes on sqlite and fails on Postgres | Nothing in this plan needs one. Keep it that way |
| 9 | `pagination.events` is 18; a client hardcoding 20 mis-sizes skeletons | `GET meta`, asserted against config |
| 10 | Route-name change `api.*` → `api.v1.*` | Verified zero call sites; `ApiVersioningTest` |

---

## Records

Most of this work is internal and correctly writes **nothing** to `public-changelog.md` —
versioning, throttles, envelopes, surfaces and the contract test are invisible to users. The PR
body is the record; there is no `CHANGELOG.md` and none should be added.

The user-facing entries, in Romanian, via the `dev-flow:public-changelog` skill:

- Google sign-in in the app
- Push notifications on the phone
- Deleting your account from the app
- Password reset
- The email-verification link actually working

---

## Verification

1. `php artisan test --compact tests/Feature/Api/` — all green, plus the new
   `tests/Feature/Auth/{EmailVerification,PasswordReset}Test.php`.
2. Manual smoke against a dev account:
   ```bash
   curl -X POST https://<host>/api/v1/auth/login \
        -d 'email=…&password=…&device_name=cli&platform=ios'
   curl -H 'Authorization: Bearer <access>' https://<host>/api/v1/recommendations
   curl -H 'Authorization: Bearer <access>' https://<host>/api/v1/meta
   ```
   Confirm `meta.page_size` is 18 and the category list matches `EventCategory::cases()`.
3. Hammer `POST /api/v1/auth/login` 20× and confirm a 429 with `retry_after` — this is gotcha 1.
4. Confirm the access token is rejected on `POST /auth/refresh` (wrong ability) and that the
   refresh token is rejected everywhere else.
5. Register a fake device, run `php artisan eventpulse:send-notifications --sync` for that user,
   and confirm one Expo call, no duplicate web push for the same `install_id`, and `sent_at` set.
6. Regression: `php artisan tinker --execute 'echo route("verification.verify", ["id"=>1,"hash"=>"x"]);'`
   now returns a URL, and `POST /profile/resend-verification` returns 200 rather than 500.
7. Validate `openapi/v1.yaml` renders in a viewer, and that `OpenApiContractTest` fails when you
   deliberately add a route without a spec entry.
