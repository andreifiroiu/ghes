<?php

declare(strict_types=1);

namespace App\Services\Scraping;

use App\Contracts\ScraperAdapter;
use App\DTOs\RawEvent;
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

        foreach (array_keys($cities) as $cityKey) {
            $this->runCity($cityKey);
        }
    }

    /**
     * Dispatch one RunScraperJob per enabled source for a single city.
     */
    public function runCity(string $cityKey): void
    {
        foreach ($this->getEnabledSources($cityKey) as $sourceConfig) {
            RunScraperJob::dispatch($cityKey, $sourceConfig);
        }
    }

    /**
     * Execute one scraper synchronously, save each event as it arrives, and return saved count.
     */
    public function runSource(string $cityKey, string $adapterKey): int
    {
        $cityConfig = $this->getCityConfig($cityKey);
        $sourceConfig = $this->findSourceConfig($cityKey, $adapterKey);
        $adapter = $this->resolveAdapter($adapterKey);

        /** @var EventPipeline $pipeline */
        $pipeline = $this->app->make(EventPipeline::class);

        $run = ScraperRun::create([
            'source' => $adapterKey,
            'city' => $cityKey,
            'status' => 'running',
            'started_at' => now(),
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
                'status' => 'completed',
                'events_found' => $found,
                'events_created' => $created,
                'events_updated' => $updated,
                'events_skipped' => $skipped,
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error("Scraper failed for {$adapterKey}@{$cityKey}", [
                'error' => $e->getMessage(),
            ]);

            $run->update([
                'status' => 'failed',
                'errors_count' => 1,
                'error_log' => [$e->getMessage()],
                'finished_at' => now(),
            ]);

            $this->alertIfConsecutiveFailuresExceedThreshold($adapterKey, $cityKey);
        }

        return $created + $updated;
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
     * @return array{adapter: string, url?: string, params?: array<string, mixed>, enabled: bool, interval_hours: int}
     *
     * @throws \InvalidArgumentException If no source config is found.
     */
    private function findSourceConfig(string $cityKey, string $adapterKey): array
    {
        /** @var list<array{adapter: string, url?: string, params?: array<string, mixed>, enabled: bool, interval_hours: int}> $sources */
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

        $consecutiveFailures = ScraperRun::where('source', $adapterKey)
            ->where('city', $cityKey)
            ->latest('started_at')
            ->take($threshold)
            ->get()
            ->every(fn (ScraperRun $run): bool => $run->status === 'failed');

        if ($consecutiveFailures) {
            Log::critical("Scraper '{$adapterKey}@{$cityKey}' has failed {$threshold} consecutive times.", [
                'adapter' => $adapterKey,
                'city' => $cityKey,
            ]);
        }
    }
}
