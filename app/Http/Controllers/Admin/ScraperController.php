<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunScraperJob;
use App\Models\ScraperRun;
use App\Services\Scraping\ScraperOrchestrator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ScraperController extends Controller
{
    public function __construct(
        private readonly ScraperOrchestrator $orchestrator,
    ) {}

    public function index(Request $request): Response
    {
        $runs = ScraperRun::query()
            ->orderBy('created_at', 'desc')
            ->paginate((int) config('eventpulse.pagination.admin_scraper_runs', 25));

        return Inertia::render('Admin/Scrapers', [
            'runs' => $runs,
            'cities' => array_keys((array) config('eventpulse.cities', [])),
            'adapters' => $this->adaptersByCity(),
        ]);
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
