<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Anthropic\AnthropicClient;
use App\Services\Scraping\ScraperOrchestrator;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
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

        Gate::define('access-admin', function ($user): bool {
            $admins = (array) config('eventpulse.admin_emails', []);

            return in_array($user->email, $admins, true);
        });

        // Restrict the Log Viewer dashboard to admins in every environment.
        // Reuses the same allow-list as the rest of the admin area.
        LogViewer::auth(fn (Request $request): bool => (bool) $request->user()?->can('access-admin'));

        RateLimiter::for('anthropic-api', function () {
            return Limit::perMinute(100);
        });
    }
}
