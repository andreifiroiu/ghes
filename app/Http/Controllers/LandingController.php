<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\ScraperRun;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class LandingController extends Controller
{
    /**
     * How many events the landing grid shows: two rows of three.
     */
    private const PREVIEW_LIMIT = 6;

    /**
     * The landing payload is identical for every guest and costs a handful of
     * aggregate queries, so it is cached rather than recomputed per visit. Ten
     * minutes keeps the "actualizat acum X minute" line honest without letting
     * the grid go stale between scraper runs.
     */
    private const CACHE_TTL_SECONDS = 600;

    public function index(): Response|RedirectResponse
    {
        if (auth()->check()) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Landing', Cache::remember(
            'landing:payload',
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->payload(),
        ));
    }

    /**
     * @return array{events: array<int, mixed>, stats: array<string, mixed>, city: string}
     */
    private function payload(): array
    {
        return [
            'events' => EventResource::collection($this->previewEvents())->resolve(),
            'stats' => $this->stats(),
            'city' => $this->cityLabel(),
        ];
    }

    /**
     * The soonest upcoming events, as a guest would see them: no reactions
     * eager-loaded, so `current_reaction` is absent from the resource.
     *
     * @return Collection<int, Event>
     */
    private function previewEvents(): Collection
    {
        return Event::upcoming()
            ->visible()
            ->canonical()
            ->orderBy('starts_at')
            ->limit(self::PREVIEW_LIMIT)
            ->get();
    }

    /**
     * @return array{active: int, sources: int, added_today: int, last_scraped_at: string|null}
     */
    private function stats(): array
    {
        return [
            'active' => Event::upcoming()->visible()->canonical()->count(),
            'sources' => $this->enabledSourceCount(),
            // `->utc()` matters: query bindings are formatted in the value's own
            // timezone, so a local midnight would be compared verbatim against UTC
            // `created_at` values and drop everything created before the offset.
            'added_today' => Event::where('created_at', '>=', Carbon::now($this->cityTimezone())->startOfDay()->utc())
                ->visible()
                ->canonical()
                ->count(),
            'last_scraped_at' => ScraperRun::whereNotNull('finished_at')
                ->latest('finished_at')
                ->value('finished_at')?->toIso8601String(),
        ];
    }

    /**
     * How many scraper sources are switched on for the active city. Read from
     * config so the figure tracks reality instead of drifting into a boast.
     */
    private function enabledSourceCount(): int
    {
        /** @var array<int, array<string, mixed>> $sources */
        $sources = config("eventpulse.cities.{$this->city()}.sources", []);

        return count(array_filter(
            $sources,
            static fn (array $source): bool => (bool) ($source['enabled'] ?? false),
        ));
    }

    private function city(): string
    {
        return (string) config('eventpulse.default_city');
    }

    private function cityLabel(): string
    {
        return (string) config("eventpulse.cities.{$this->city()}.label", 'Timișoara');
    }

    private function cityTimezone(): string
    {
        return (string) config("eventpulse.cities.{$this->city()}.timezone", config('app.timezone'));
    }
}
