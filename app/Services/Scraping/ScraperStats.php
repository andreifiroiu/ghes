<?php

declare(strict_types=1);

namespace App\Services\Scraping;

use App\Enums\ScraperRunStatus;
use App\Http\Controllers\Admin\Concerns\FiltersAdminEvents;
use App\Models\Event;
use App\Models\ScraperRun;
use App\Services\Processing\EventTextNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Per-source reporting for the admin scrapers screens.
 *
 * Days are calendar days in the *city's* timezone, not UTC — a run at 22:30 UTC
 * belongs to the following local day in Europe/Bucharest, and an admin reading
 * "yesterday" means the local day. This mirrors the convention in
 * {@see FiltersAdminEvents}.
 *
 * Bucketing happens in PHP rather than SQL on purpose: production is PostgreSQL
 * but the test suite runs on SQLite, and `date_trunc` is not portable between
 * them. The windows involved are at most 90 days of runs, so the cost is trivial.
 *
 * @phpstan-type DailyStat array{day: string, runs: int, completed: int, failed: int, running: int, found: int, created: int, updated: int, skipped: int, errors: int}
 * @phpstan-type SourceTotals array{runs: int, completed: int, failed: int, running: int, found: int, created: int, updated: int, skipped: int, errors: int}
 */
final readonly class ScraperStats
{
    public function __construct(private ScraperOrchestrator $orchestrator) {}

    /**
     * Day-by-day counters plus a health summary for one adapter in one city.
     *
     * @return array{days: list<DailyStat>, totals: SourceTotals, health: array<string, mixed>}
     */
    public function forSource(string $cityKey, string $adapterKey, int $days): array
    {
        $cityConfig = $this->orchestrator->getCityConfig($cityKey);
        $timezone = $cityConfig['timezone'];

        $today = CarbonImmutable::now($timezone)->startOfDay();
        $windowStart = $today->subDays($days - 1);

        $runs = ScraperRun::query()
            ->where('source', $adapterKey)
            ->where('city', $cityKey)
            ->where('started_at', '>=', $windowStart->utc())
            ->orderBy('started_at')
            ->get();

        $byDay = $runs->groupBy(
            fn (ScraperRun $run): string => $run->started_at->setTimezone($timezone)->toDateString()
        );

        $daily = [];

        // Zero-fill every day in the window. A source that silently stopped
        // running must show a gap, not vanish from the series.
        for ($offset = 0; $offset < $days; $offset++) {
            $day = $windowStart->addDays($offset)->toDateString();

            $daily[] = $this->summarise($day, $byDay->get($day) ?? collect());
        }

        return [
            'days' => $daily,
            'totals' => $this->totals($daily),
            'health' => $this->health($cityKey, $adapterKey, $cityConfig, $runs),
        ];
    }

    /**
     * The latest run for every configured adapter+city pair, keyed "adapter|city".
     *
     * One query for the whole overview table rather than one per source.
     *
     * @param  list<array{adapter: string, city: string}>  $pairs
     * @return array<string, ScraperRun>
     */
    public function latestRunFor(array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }

        $adapters = array_values(array_unique(array_column($pairs, 'adapter')));
        $cities = array_values(array_unique(array_column($pairs, 'city')));

        // Two bounded queries rather than one unbounded one: find each pair's
        // most recent start, then fetch just those rows. Loading the whole table
        // to keep a dozen rows would grow without limit — nothing prunes
        // scraper_runs — and eventually exhaust memory on the admin index.
        /** @var Collection<int, object{source: string, city: string|null, latest_started_at: string}> $latestStarts */
        $latestStarts = ScraperRun::query()
            ->whereIn('source', $adapters)
            ->whereIn('city', $cities)
            ->groupBy('source', 'city')
            ->selectRaw('source, city, MAX(started_at) as latest_started_at')
            ->toBase()
            ->get();

        if ($latestStarts->isEmpty()) {
            return [];
        }

        $runs = ScraperRun::query()
            ->where(function ($query) use ($latestStarts): void {
                foreach ($latestStarts as $row) {
                    $query->orWhere(fn ($pair) => $pair
                        ->where('source', $row->source)
                        ->where('city', $row->city)
                        ->where('started_at', $row->latest_started_at));
                }
            })
            ->orderByDesc('started_at')
            ->get();

        return $runs
            ->unique(fn (ScraperRun $run): string => $run->source.'|'.$run->city)
            ->keyBy(fn (ScraperRun $run): string => $run->source.'|'.$run->city)
            ->all();
    }

    /**
     * @param  Collection<int, ScraperRun>  $runs
     * @return DailyStat
     */
    private function summarise(string $day, Collection $runs): array
    {
        return [
            'day' => $day,
            'runs' => $runs->count(),
            'completed' => $runs->where('status', ScraperRunStatus::Completed)->count(),
            'failed' => $runs->where('status', ScraperRunStatus::Failed)->count(),
            'running' => $runs->where('status', ScraperRunStatus::Running)->count(),
            'found' => (int) $runs->sum('events_found'),
            'created' => (int) $runs->sum('events_created'),
            'updated' => (int) $runs->sum('events_updated'),
            'skipped' => (int) $runs->sum('events_skipped'),
            'errors' => (int) $runs->sum('errors_count'),
        ];
    }

    /**
     * @param  list<DailyStat>  $daily
     * @return SourceTotals
     */
    private function totals(array $daily): array
    {
        $sum = fn (string $key): int => (int) array_sum(array_column($daily, $key));

        return [
            'runs' => $sum('runs'),
            'completed' => $sum('completed'),
            'failed' => $sum('failed'),
            // Carried through, not dropped: a day whose runs are all still
            // `running` has zero failures but is not a healthy day, and
            // reporting only "Failed 0" would assert that nothing went wrong.
            'running' => $sum('running'),
            'found' => $sum('found'),
            'created' => $sum('created'),
            'updated' => $sum('updated'),
            'skipped' => $sum('skipped'),
            'errors' => $sum('errors'),
        ];
    }

    /**
     * Is-this-source-working signals. Unlike the daily series these look at all
     * of history, not just the selected window — "last successful run" is only
     * useful if it can reach back past the window.
     *
     * @param  array{label: string, timezone: string, coordinates: list<float>, radius_km: int, sources: list<array<string, mixed>>}  $cityConfig
     * @param  Collection<int, ScraperRun>  $windowRuns
     * @return array<string, mixed>
     */
    private function health(string $cityKey, string $adapterKey, array $cityConfig, Collection $windowRuns): array
    {
        $lastRun = ScraperRun::query()
            ->where('source', $adapterKey)
            ->where('city', $cityKey)
            ->latest('started_at')
            ->first();

        $lastSuccess = ScraperRun::query()
            ->where('source', $adapterKey)
            ->where('city', $cityKey)
            ->where('status', ScraperRunStatus::Completed)
            ->latest('started_at')
            ->first();

        $completed = $windowRuns->where('status', ScraperRunStatus::Completed);
        $resolved = $windowRuns->whereIn('status', ScraperRunStatus::resolved());

        $durations = $completed
            ->filter(fn (ScraperRun $run): bool => $run->finished_at !== null)
            ->map(fn (ScraperRun $run): int => (int) $run->finished_at->diffInSeconds($run->started_at, absolute: true));

        $citySlug = EventTextNormalizer::citySlug($cityConfig['label']);

        $eventsQuery = fn (): Builder => Event::query()
            ->where('source', $adapterKey)
            ->when($citySlug !== null, fn ($query) => $query->where('city_slug', $citySlug))
            ->canonical()
            ->visible();

        return [
            'last_run' => $this->describeRun($lastRun),
            'last_success' => $this->describeRun($lastSuccess),
            'consecutive_failures' => $this->consecutiveFailures($cityKey, $adapterKey),
            'failure_threshold' => (int) config('eventpulse.scraping.max_consecutive_failures', 3),
            'success_rate' => $resolved->isEmpty()
                ? null
                : round($completed->count() / $resolved->count(), 4),
            'avg_duration_seconds' => $durations->isEmpty() ? null : (int) round($durations->avg()),
            'events_total' => $eventsQuery()->count(),
            'events_upcoming' => $eventsQuery()->upcoming()->count(),
        ];
    }

    /**
     * @return array{status: string, started_at: string, finished_at: string|null, duration_seconds: int|null, events_created: int, events_found: int}|null
     */
    private function describeRun(?ScraperRun $run): ?array
    {
        if ($run === null) {
            return null;
        }

        return [
            'status' => $run->status->value,
            'started_at' => $run->started_at->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'duration_seconds' => $run->finished_at === null
                ? null
                : (int) $run->finished_at->diffInSeconds($run->started_at, absolute: true),
            'events_created' => $run->events_created,
            'events_found' => $run->events_found,
        ];
    }

    /**
     * How many runs have failed in a row, counting back from the most recent.
     *
     * Delegates to the model so the number shown on the page and the number the
     * orchestrator alerts on can never drift apart.
     */
    private function consecutiveFailures(string $cityKey, string $adapterKey): int
    {
        return ScraperRun::consecutiveFailuresFor($adapterKey, $cityKey);
    }
}
