<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Services\Anthropic\AnthropicClient;
use App\Services\Scraping\ScraperOrchestrator;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Laravel\Scout\Scout;
use Meilisearch\Client;
use Opcodes\LogViewer\Facades\LogViewer;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AnthropicClient::class, function () {
            return new AnthropicClient(
                apiKey: (string) config('eventpulse.llm.api_key'),
                model: (string) config('eventpulse.llm.model'),
                maxTokens: (int) config('eventpulse.llm.max_tokens', 1024),
            );
        });

        $this->app->singleton(ScraperOrchestrator::class, fn ($app) => new ScraperOrchestrator($app));

        // Scout builds its Meilisearch client without an HTTP client, so Guzzle
        // applies no timeout at all. That was survivable when a search happened
        // on form submit; the browse search now runs as the user types, and a
        // hanging index would hold a PHP worker per keystroke. EventSearcher's
        // circuit breaker cannot help here — it only trips once a call throws,
        // and a hang never does.
        //
        // This replaces Scout's singleton, so the budget applies to *every*
        // Meilisearch call, writes included. Hence the split: a short
        // connect timeout catches the common "host is gone" case immediately,
        // while the request timeout stays generous enough for a `scout:import`
        // batch or a settings sync, which a 2s ceiling would have broken. A
        // read that does hit the ceiling trips the breaker, so only one request
        // per minute pays it.
        $this->app->singleton(Client::class, fn () => new Client(
            (string) config('scout.meilisearch.host'),
            config('scout.meilisearch.key'),
            new GuzzleClient([
                'connect_timeout' => (float) config('eventpulse.search.connect_timeout', 1.0),
                'timeout' => (float) config('eventpulse.search.timeout', 2.0),
            ]),
            // Kept identical to Scout's own binding, which this replaces: the
            // agent string is what identifies these calls to Meilisearch, and
            // dropping it would be an unrelated, invisible change.
            clientAgents: [sprintf('Meilisearch Laravel Scout (v%s)', Scout::VERSION)],
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        JsonResource::withoutWrapping();

        // Tokens carry the device they were issued to.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        Gate::define('access-admin', function ($user): bool {
            $admins = (array) config('eventpulse.admin_emails', []);

            return in_array($user->email, $admins, true);
        });

        // Restrict the Log Viewer dashboard to admins in every environment.
        // Reuses the same allow-list as the rest of the admin area.
        LogViewer::auth(fn (Request $request): bool => (bool) $request->user()?->can('access-admin'));

        // Consumed by ClassifyEventJob through the RateLimited job middleware.
        RateLimiter::for('anthropic-api', function () {
            return Limit::perMinute(100);
        });

        // The whole /api group, applied by throttleApi() in bootstrap/app.php.
        // Keyed by the authenticated user when there is one, so one abusive
        // token cannot exhaust the budget of everyone behind the same NAT.
        // The sanctum guard is named explicitly: the default guard is `web`,
        // which has no session here and would key every bearer request by IP.
        //
        // A request that carries a token the guard rejects (revoked, expired)
        // is keyed by that token, not the IP: after a mass revocation, every
        // stale client behind one NAT would otherwise drain a single bucket
        // and see `rate_limited` instead of the `unauthenticated` that makes
        // it sign in again. Only a request with no token at all keys by IP.
        RateLimiter::for('api', function (Request $request) {
            // 0 or blank is never "unlimited" — it would be one request a minute.
            $perMinute = (int) config('eventpulse.api.throttle.per_minute');
            $perMinute = $perMinute > 0 ? $perMinute : 120;
            $token = $request->bearerToken();

            $key = $request->user('sanctum')?->getAuthIdentifier()
                ?? ($token !== null ? 'token:'.hash('sha256', $token) : $request->ip());

            return Limit::perMinute($perMinute)->by((string) $key);
        });

        // Credential guessing: keyed by the address being tried plus the IP,
        // so one attacker cannot lock a victim out from everywhere, and one
        // NAT cannot be locked out by one bad neighbour.
        // Two dimensions: per address+IP for the account being attacked,
        // and per IP alone so cycling addresses is bounded as well.
        RateLimiter::for('api-auth', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email', '')));

            return [
                Limit::perMinute((int) config('eventpulse.api.throttle.auth_per_minute', 5))
                    ->by($email.'|'.$request->ip()),
                Limit::perMinute((int) config('eventpulse.api.throttle.auth_per_minute_per_ip', 20))
                    ->by('ip|'.$request->ip()),
            ];
        });

        // Password re-checks by an already signed-in user (account deletion).
        // Keyed by the account, so a stolen token gets the same handful of
        // guesses a login attempt would, wherever it is used from.
        RateLimiter::for('api-reauth', function (Request $request) {
            return Limit::perMinute((int) config('eventpulse.api.throttle.auth_per_minute', 5))
                ->by('reauth|'.($request->user('sanctum')?->getAuthIdentifier() ?? $request->ip()));
        });

        // Every chat POST is an LLM call. Keyed by user (the routes are
        // authenticated); the daily ceiling is what bounds the bill.
        RateLimiter::for('api-chat', function (Request $request) {
            $key = 'chat|'.($request->user('sanctum')?->getAuthIdentifier() ?? $request->ip());

            return [
                Limit::perMinute((int) config('eventpulse.api.throttle.chat_per_minute', 20))->by($key),
                Limit::perDay((int) config('eventpulse.api.throttle.chat_per_day', 200))->by($key),
            ];
        });

        RateLimiter::for('api-register', function (Request $request) {
            return Limit::perHour((int) config('eventpulse.api.throttle.register_per_hour', 10))
                ->by((string) $request->ip());
        });

        // Keyed by the device whose pair is being rotated. Runs after
        // auth:sanctum by middleware priority, so the token is resolved.
        RateLimiter::for('api-refresh', function (Request $request) {
            $token = $request->user('sanctum')?->currentAccessToken();
            $deviceId = $token instanceof PersonalAccessToken ? $token->device_id : null;

            return Limit::perMinute((int) config('eventpulse.api.throttle.refresh_per_minute', 30))
                ->by((string) ($deviceId ?? $request->ip()));
        });
    }
}
