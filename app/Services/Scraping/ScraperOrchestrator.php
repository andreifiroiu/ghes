<?php

declare(strict_types=1);

namespace App\Services\Scraping;

use App\Contracts\ScraperAdapter;
use App\DTOs\RawEvent;
use App\Enums\ScraperRunStatus;
use App\Jobs\RunScraperJob;
use App\Models\ScraperRun;
use App\Services\Processing\EventPipeline;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Throwable;

class ScraperOrchestrator
{
    public function __construct(private readonly Application $app) {}

    /**
     * Dispatch one RunScraperJob per enabled source across all configured cities.
     */
    public function runAll(): void
    {
        /** @var array<string, mixed> $cities */
        $cities = config('eventpulse.cities', []);

        Log::info('ScraperOrchestrator: runAll dispatching', ['cities' => array_keys($cities)]);

        foreach (array_keys($cities) as $cityKey) {
            $this->runCity($cityKey);
        }
    }

    /**
     * Dispatch one RunScraperJob per enabled source for a single city.
     */
    public function runCity(string $cityKey): void
    {
        $sources = $this->getEnabledSources($cityKey);

        Log::info('ScraperOrchestrator: runCity dispatching', [
            'city' => $cityKey,
            'enabled_sources' => count($sources),
            'adapters' => array_column($sources, 'adapter'),
        ]);

        foreach ($sources as $sourceConfig) {
            RunScraperJob::dispatch($cityKey, $sourceConfig);
        }
    }

    /**
     * Execute one scraper synchronously, save each event as it arrives, and return saved count.
     *
     * @param  string|null  $jobUuid  Queue payload UUID, when this run is driven by a
     *                                RunScraperJob. It survives retries, so passing it
     *                                keeps every attempt of one dispatch on a single row
     *                                instead of leaving a stale `running` row per attempt.
     */
    public function runSource(string $cityKey, string $adapterKey, ?string $jobUuid = null): int
    {
        $cityConfig = $this->getCityConfig($cityKey);
        $sourceConfig = $this->findSourceConfig($cityKey, $adapterKey);
        $adapter = $this->resolveAdapter($adapterKey);

        /** @var EventPipeline $pipeline */
        $pipeline = $this->app->make(EventPipeline::class);

        $run = $this->startRun($cityKey, $adapterKey, $jobUuid);

        Log::info("runSource: starting {$adapterKey}@{$cityKey}", [
            'adapter' => $adapterKey,
            'city' => $cityKey,
            'run_id' => $run->id,
        ]);

        $found = 0;
        $created = 0;
        $updated = 0;
        $skipped = 0;

        try {
            $adapter->scrape(
                $sourceConfig,
                $cityConfig,
                function (RawEvent $event) use ($pipeline, $cityKey, $adapterKey, &$found, &$created, &$updated, &$skipped): void {
                    $found++;

                    try {
                        $processed = $pipeline->process($event, $cityKey);

                        if ($processed === null) {
                            $skipped++;
                        } elseif ($processed->wasRecentlyCreated) {
                            $created++;
                        } else {
                            $updated++;
                        }
                    } catch (Throwable $e) {
                        $skipped++;

                        Log::error("runSource: failed to process event for {$adapterKey}", [
                            'title' => $event->title,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            );

            $run->update([
                'status' => ScraperRunStatus::Completed,
                'events_found' => $found,
                'events_created' => $created,
                'events_updated' => $updated,
                'events_skipped' => $skipped,
                'finished_at' => now(),
            ]);

            Log::info("runSource: completed {$adapterKey}@{$cityKey}", [
                'adapter' => $adapterKey,
                'city' => $cityKey,
                'events_found' => $found,
                'events_created' => $created,
                'events_updated' => $updated,
                'events_skipped' => $skipped,
            ]);
        } catch (Throwable $e) {
            Log::error("Scraper failed for {$adapterKey}@{$cityKey}", [
                'error' => $e->getMessage(),
            ]);

            // Keep whatever the adapter managed to produce before it threw.
            // Discarding these made a run that scraped 40 events and then died
            // report zero, which reads as "this source returns nothing" rather
            // than "this source broke part way through".
            $run->update([
                'status' => ScraperRunStatus::Failed,
                'events_found' => $found,
                'events_created' => $created,
                'events_updated' => $updated,
                'events_skipped' => $skipped,
                'errors_count' => 1,
                'error_log' => [$e->getMessage()],
                'finished_at' => now(),
            ]);

            $this->alertIfConsecutiveFailuresExceedThreshold($adapterKey, $cityKey);
        }

        return $created + $updated;
    }

    /**
     * Open the row this run will report into.
     *
     * A job carries a UUID that survives its retries, so an attempt after a
     * worker died reuses — and resets — the row the dead attempt opened, rather
     * than abandoning it as `running` forever and starting a fresh one. Runs
     * with no job behind them (the CLI command) always get their own row.
     */
    private function startRun(string $cityKey, string $adapterKey, ?string $jobUuid): ScraperRun
    {
        $opening = [
            'source' => $adapterKey,
            'city' => $cityKey,
            'job_uuid' => $jobUuid,
            'status' => ScraperRunStatus::Running,
            'started_at' => now(),
            'finished_at' => null,
            'events_found' => 0,
            'events_created' => 0,
            'events_updated' => 0,
            'events_skipped' => 0,
            'errors_count' => 0,
            'error_log' => [],
        ];

        if ($jobUuid === null) {
            return ScraperRun::create($opening);
        }

        $existing = ScraperRun::query()
            ->where('job_uuid', $jobUuid)
            ->latest('started_at')
            ->first();

        if ($existing === null) {
            return ScraperRun::create($opening);
        }

        // A row still running and younger than the job's own timeout means the
        // previous attempt is alive, not dead — the queue re-reserved a job that
        // had not finished. Resetting it would destroy a working run's counters
        // and hand two workers the same row. Open a separate one instead, so the
        // double delivery stays visible rather than corrupting a single record.
        if ($existing->status === ScraperRunStatus::Running && $existing->started_at->gt(now()->subSeconds(RunScraperJob::TIMEOUT_SECONDS))) {
            Log::warning("startRun: {$adapterKey}@{$cityKey} was delivered again while still running", [
                'adapter' => $adapterKey,
                'city' => $cityKey,
                'job_uuid' => $jobUuid,
                'live_run_id' => $existing->id,
                'hint' => 'queue retry_after is probably below the job timeout',
            ]);

            return ScraperRun::create($opening);
        }

        $existing->update($opening);

        return $existing;
    }

    /**
     * Mark a dispatch's row failed when the queue gives up on it.
     *
     * Without this a job killed by the worker (timeout, OOM) leaves its row
     * stuck on `running` forever, which reads as "still going" long after
     * nothing is.
     */
    public function abandonRun(string $jobUuid, string $reason): void
    {
        $run = ScraperRun::query()
            ->where('job_uuid', $jobUuid)
            ->where('status', ScraperRunStatus::Running)
            ->orderByDesc('started_at')
            ->first();

        if ($run === null) {
            // Either the run already resolved itself — fine — or no row was ever
            // opened, because runSource() threw before startRun() (unknown city,
            // a source dropped from config, an unregistered adapter). That second
            // case leaves a broken scraper looking merely idle, so say so.
            Log::warning('abandonRun: no open run to close out', [
                'job_uuid' => $jobUuid,
                'reason' => $reason,
                'hint' => 'if no run was ever opened, the job failed before it could start one',
            ]);

            return;
        }

        $run->update([
            'status' => ScraperRunStatus::Failed,
            'errors_count' => max(1, $run->errors_count),
            'error_log' => [...$run->error_log, $reason],
            'finished_at' => now(),
        ]);

        if ($run->city !== null) {
            $this->alertIfConsecutiveFailuresExceedThreshold($run->source, $run->city);
        }
    }

    /**
     * Resolve an adapter instance from the registry by its key.
     *
     * @throws \InvalidArgumentException If the key has no registered class.
     */
    public function resolveAdapter(string $adapterKey): ScraperAdapter
    {
        /** @var array<string, class-string<ScraperAdapter>> $registry */
        $registry = config('eventpulse.adapter_registry', []);

        if (! isset($registry[$adapterKey])) {
            throw new \InvalidArgumentException("No adapter registered for key: {$adapterKey}");
        }

        return $this->app->make($registry[$adapterKey]);
    }

    /**
     * Return the full city config array for the given city key.
     *
     * @return array{label: string, timezone: string, coordinates: list<float>, radius_km: int, sources: list<array<string, mixed>>}
     *
     * @throws \InvalidArgumentException If the city key is not configured.
     */
    public function getCityConfig(string $cityKey): array
    {
        /** @var array<string, mixed>|null $config */
        $config = config("eventpulse.cities.{$cityKey}");

        if ($config === null) {
            throw new \InvalidArgumentException("No city configured for key: {$cityKey}");
        }

        // Callers — adapters included — index these directly, so a hand-written
        // city entry missing one must fail here, naming the key, rather than as
        // an "Undefined array key" somewhere further down.
        foreach (['label', 'timezone'] as $required) {
            if (! isset($config[$required])) {
                throw new \InvalidArgumentException(
                    "City '{$cityKey}' is missing the required '{$required}' key."
                );
            }
        }

        return $config;
    }

    /**
     * Return only the enabled sources for a city that have a registered adapter.
     *
     * @return list<array{adapter: string, url?: string, params?: array<string, mixed>, enabled: bool, interval_hours: int}>
     */
    public function getEnabledSources(string $cityKey): array
    {
        /** @var array<string, class-string<ScraperAdapter>> $registry */
        $registry = config('eventpulse.adapter_registry', []);

        /** @var list<array{adapter: string, url?: string, params?: array<string, mixed>, enabled: bool, interval_hours: int}> $sources */
        $sources = config("eventpulse.cities.{$cityKey}.sources", []);

        return array_values(
            array_filter(
                $sources,
                fn (array $s): bool => $s['enabled'] && isset($registry[$s['adapter']]),
            ),
        );
    }

    /**
     * Find the source config entry for a specific adapter key within a city.
     *
     * The shape matches {@see ScraperAdapter::scrape()}: only
     * `adapter`, `enabled` and `interval_hours` are guaranteed. URL-driven
     * adapters carry `url`; API-driven ones (eventbrite, facebook_events)
     * carry `params` instead.
     *
     * @return array{adapter: string, url?: string, extra_urls?: list<string>, enabled: bool, interval_hours: int, country?: string, city_filter?: string, params?: array<string, mixed>}
     *
     * @throws \InvalidArgumentException If no source config is found.
     */
    public function findSourceConfig(string $cityKey, string $adapterKey): array
    {
        /** @var list<array{adapter: string, url?: string, extra_urls?: list<string>, enabled: bool, interval_hours: int, country?: string, city_filter?: string, params?: array<string, mixed>}> $sources */
        $sources = config("eventpulse.cities.{$cityKey}.sources", []);

        foreach ($sources as $source) {
            if ($source['adapter'] === $adapterKey) {
                return $source;
            }
        }

        throw new \InvalidArgumentException("No source config found for adapter '{$adapterKey}' in city '{$cityKey}'");
    }

    private function alertIfConsecutiveFailuresExceedThreshold(string $adapterKey, string $cityKey): void
    {
        $threshold = (int) config('eventpulse.scraping.max_consecutive_failures', 3);

        // Shares its definition with the admin page, which displays this number
        // next to the threshold. `every()` on a short collection returns true,
        // so the old form alerted on a brand-new source's very first failure.
        $streak = ScraperRun::consecutiveFailuresFor($adapterKey, $cityKey);

        if ($streak >= $threshold) {
            Log::critical("Scraper '{$adapterKey}@{$cityKey}' has failed {$streak} consecutive times.", [
                'adapter' => $adapterKey,
                'city' => $cityKey,
                'consecutive_failures' => $streak,
                'threshold' => $threshold,
            ]);
        }
    }
}
