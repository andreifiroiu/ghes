<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LogsConsoleOutput;
use App\Services\Scraping\ScraperOrchestrator;
use Illuminate\Console\Command;

class ScrapeEventsCommand extends Command
{
    use LogsConsoleOutput;

    protected $signature = 'eventpulse:scrape
        {--city= : Run scrapers for a specific city key (default: all cities)}
        {--source= : Run a specific adapter key within the city (requires --city)}
        {--limit= : Override max_pages for this run (useful for quick tests)}';

    protected $description = 'Run event scrapers to fetch new events from configured sources';

    public function handle(ScraperOrchestrator $orchestrator): int
    {
        $city = $this->option('city');
        $source = $this->option('source');

        if (is_string($this->option('limit'))) {
            config(['eventpulse.scrapers.max_pages' => (int) $this->option('limit')]);
        }

        if (is_string($city) && is_string($source)) {
            $this->info("Running scraper for {$source}@{$city}...");
            $saved = $orchestrator->runSource($city, $source);
            $this->info("Saved {$saved} new events to database.");
        } elseif (is_string($city)) {
            $this->info("Dispatching scraper jobs for city: {$city}...");
            $orchestrator->runCity($city);
            $this->info('Scraper jobs dispatched.');
        } else {
            $this->info('Dispatching scraper jobs for all cities...');
            $orchestrator->runAll();
            $this->info('Scraper jobs dispatched.');
        }

        return self::SUCCESS;
    }
}
