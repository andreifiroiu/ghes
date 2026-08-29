<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunScraperJob;
use App\Models\ScraperRun;
use App\Services\Scraping\ScraperOrchestrator;
use App\Services\Scraping\ScraperStats;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class ScraperController extends Controller
{
    public function __construct(
        private readonly ScraperOrchestrator $orchestrator,
        private readonly ScraperStats $stats,
    ) {}

    public function index(Request $request): Response
    {
        $runs = ScraperRun::query()
            ->orderBy('created_at', 'desc')
            ->paginate((int) config('eventpulse.pagination.admin_scraper_runs', 25));

        return Inertia::render('Admin/Scrapers/Index', [
            'runs' => $runs,
            'cities' => array_keys((array) config('eventpulse.cities', [])),
            'adapters' => $this->adaptersByCity(),
            'sources' => $this->configuredSources(),
        ]);
    }

    /**
     * Every configured adapter+city pair with its latest run, so the index can
     * show at a glance which sources are healthy and link into their details.
     *
     * @return list<array{adapter: string, city: string, city_label: string, enabled: bool, interval_hours: int|null, url: string|null, last_run: array<string, mixed>|null}>
     */
    private function configuredSources(): array
    {
        /** @var array<string, array{label?: string, sources?: array<int, array<string, mixed>>}> $cities */
        $cities = config('eventpulse.cities', []);

        $pairs = [];

        foreach ($cities as $cityKey => $city) {
            foreach ($city['sources'] ?? [] as $source) {
                $pairs[] = ['adapter' => (string) $source['adapter'], 'city' => (string) $cityKey];
            }
        }

        $latest = $this->stats->latestRunFor($pairs);

        $rows = [];

        foreach ($cities as $cityKey => $city) {
            foreach ($city['sources'] ?? [] as $source) {
                $adapter = (string) $source['adapter'];
                $run = $latest[$adapter.'|'.$cityKey] ?? null;

                $rows[] = [
                    'adapter' => $adapter,
                    'city' => (string) $cityKey,
                    'city_label' => (string) ($city['label'] ?? $cityKey),
                    'enabled' => (bool) ($source['enabled'] ?? false),
                    'interval_hours' => isset($source['interval_hours']) ? (int) $source['interval_hours'] : null,
                    'url' => isset($source['url']) ? (string) $source['url'] : null,
                    'last_run' => $run === null ? null : [
                        'status' => $run->status->value,
                        'started_at' => $run->started_at->toIso8601String(),
                        'finished_at' => $run->finished_at?->toIso8601String(),
                        'events_found' => $run->events_found,
                        'events_created' => $run->events_created,
                    ],
                ];
            }
        }

        return $rows;
    }

    /**
     * Health and day-by-day stats for one configured source in one city.
     */
    public function show(Request $request, string $city, string $source): Response
    {
        /** @var array<string, mixed>|null $cityConfig */
        $cityConfig = config("eventpulse.cities.{$city}");

        if ($cityConfig === null) {
            abort(404);
        }

        try {
            $sourceConfig = $this->orchestrator->findSourceConfig($city, $source);
        } catch (InvalidArgumentException) {
            // A source that city has not configured has no page — this is a bad
            // URL, not a recoverable user action like a mis-picked form option.
            abort(404);
        }

        $range = $this->resolveRange($request);

        $runs = ScraperRun::query()
            ->where('source', $source)
            ->where('city', $city)
            ->orderByDesc('started_at')
            ->paginate((int) config('eventpulse.pagination.admin_scraper_detail_runs', 20))
            ->withQueryString();

        return Inertia::render('Admin/Scrapers/Show', [
            'source' => [
                'adapter' => $source,
                'city' => $city,
                'city_label' => (string) ($cityConfig['label'] ?? $city),
                'timezone' => (string) ($cityConfig['timezone'] ?? config('app.timezone')),
                'enabled' => $sourceConfig['enabled'],
                'interval_hours' => $sourceConfig['interval_hours'],
                // Absent for API-backed adapters such as eventbrite and
                // facebook_events, which are driven entirely by `params`.
                'url' => isset($sourceConfig['url']) ? (string) $sourceConfig['url'] : null,
                'extra_urls' => $sourceConfig['extra_urls'] ?? [],
                'params' => $sourceConfig['params'] ?? [],
                'city_filter' => $sourceConfig['city_filter'] ?? null,
            ],
            'stats' => $this->stats->forSource($city, $source, $range),
            'range' => $range,
            'ranges' => array_values((array) config('eventpulse.admin.scraper_stat_ranges', [7, 30, 90])),
            'runs' => $runs,
        ]);
    }

    /**
     * The requested window, or the default when it is not one we offer. An odd
     * `?range=` is clamped rather than rejected — it only ever widens or
     * narrows a chart, so a 422 would be theatre.
     */
    private function resolveRange(Request $request): int
    {
        /** @var list<int> $allowed */
        $allowed = array_map('intval', (array) config('eventpulse.admin.scraper_stat_ranges', [7, 30, 90]));
        $default = (int) config('eventpulse.admin.scraper_stat_default_range', 30);

        $requested = $request->integer('range');

        return in_array($requested, $allowed, true) ? $requested : $default;
    }

    /**
     * Adapter keys configured for each city, so the UI only ever offers a source
     * that city actually has. Disabled sources are included — an admin may want
     * to trigger one manually — and flagged so the UI can label them.
     *
     * @return array<string, list<array{adapter: string, enabled: bool}>>
     */
    private function adaptersByCity(): array
    {
        /** @var array<string, array{sources?: array<int, array<string, mixed>>}> $cities */
        $cities = config('eventpulse.cities', []);

        return collect($cities)
            ->map(fn (array $city) => collect($city['sources'] ?? [])
                ->map(fn (array $source) => [
                    'adapter' => (string) $source['adapter'],
                    'enabled' => (bool) ($source['enabled'] ?? false),
                ])
                ->values()
                ->all())
            ->all();
    }

    public function store(Request $request): RedirectResponse
    {
        $validCities = array_keys((array) config('eventpulse.cities', []));
        $validAdapters = array_keys((array) config('eventpulse.adapter_registry', []));

        /** @var array{city?: string|null, source?: string|null} $validated */
        $validated = $request->validate([
            'city' => ['nullable', 'string', Rule::in($validCities)],
            'source' => ['nullable', 'string', Rule::in($validAdapters)],
        ]);

        $city = $validated['city'] ?? null;
        $source = $validated['source'] ?? null;

        if ($city !== null && $source !== null) {
            /** @var array<int, array<string, mixed>> $sources */
            $sources = config("eventpulse.cities.{$city}.sources", []);
            $sourceConfig = collect($sources)->firstWhere('adapter', $source);

            if ($sourceConfig === null) {
                return back()->with('error', "Source '{$source}' is not configured for '{$city}'.");
            }

            RunScraperJob::dispatch($city, $sourceConfig);
            $message = "Queued {$source} for {$city}.";
        } elseif ($city !== null) {
            $this->orchestrator->runCity($city);
            $message = "Queued all sources for {$city}.";
        } else {
            $this->orchestrator->runAll();
            $message = 'Queued all sources for all cities.';
        }

        return redirect()->route('admin.scrapers.index')->with('success', $message);
    }
}
