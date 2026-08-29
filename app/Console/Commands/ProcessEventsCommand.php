<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ClassifyEventJob;
use App\Models\Event;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ProcessEventsCommand extends Command
{
    protected $signature = 'eventpulse:process-events';

    protected $description = 'Queue classification for events that have not been classified yet';

    public function handle(): int
    {
        $this->info('Fetching unclassified events...');

        $queued = 0;

        Event::query()
            ->canonical()
            ->where('is_classified', false)
            ->orderBy('id')
            ->chunkById(100, function (Collection $events) use (&$queued): void {
                /** @var Collection<int, Event> $events */
                foreach ($events as $event) {
                    ClassifyEventJob::dispatch($event->id);
                    $queued++;
                }
            });

        if ($queued === 0) {
            $this->info('No unclassified events found.');

            return self::SUCCESS;
        }

        $this->info("Queued {$queued} events for classification.");

        return self::SUCCESS;
    }
}
