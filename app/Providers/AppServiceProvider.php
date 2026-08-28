<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Anthropic\AnthropicClient;
use App\Services\Scraping\ScraperOrchestrator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
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
