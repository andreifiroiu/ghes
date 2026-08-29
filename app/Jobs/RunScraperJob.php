<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Scraping\ScraperOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunScraperJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    /**
     * Allow up to 10 minutes — scraper sleeps between requests to avoid rate
     * limits. The queue's `retry_after` must stay above this, or a slow scrape
     * is re-reserved and run twice concurrently.
     */
    public const TIMEOUT_SECONDS = 600;

    public int $timeout = self::TIMEOUT_SECONDS;

    /**
     * @param  array{adapter: string, url: string, enabled: bool, interval_hours: int}  $sourceConfig
     */
    public function __construct(
        public readonly string $cityKey,
        public readonly array $sourceConfig,
    ) {
        $this->onQueue('scraping');
    }

    public function handle(ScraperOrchestrator $orchestrator): void
    {
        $adapter = $this->sourceConfig['adapter'];

        Log::info('RunScraperJob: starting', [
            'city' => $this->cityKey,
            'adapter' => $adapter,
            'attempt' => $this->attempts(),
        ]);

        // The payload UUID is stable across retries, so every attempt of this
        // dispatch reports into one row instead of leaving one behind per attempt.
        $saved = $orchestrator->runSource($this->cityKey, $adapter, $this->job?->uuid());

        Log::info('RunScraperJob: finished', [
            'city' => $this->cityKey,
            'adapter' => $adapter,
            'events_saved' => $saved,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('RunScraperJob: failed permanently', [
            'city' => $this->cityKey,
            'adapter' => $this->sourceConfig['adapter'] ?? 'unknown',
            'error' => $e->getMessage(),
        ]);

        // runSource() resolves its own row on a scrape error, so reaching here
        // means the worker died before it could — a timeout or an OOM kill.
        // Close the row out, or it stays `running` forever.
        $uuid = $this->job?->uuid();

        if ($uuid !== null) {
            app(ScraperOrchestrator::class)->abandonRun(
                $uuid,
                'Job failed permanently: '.$e->getMessage(),
            );
        }
    }
}
