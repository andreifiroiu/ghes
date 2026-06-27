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

    /** Allow up to 10 minutes — scraper sleeps between requests to avoid rate limits. */
    public int $timeout = 600;

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

        $saved = $orchestrator->runSource($this->cityKey, $adapter);

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
    }
}
