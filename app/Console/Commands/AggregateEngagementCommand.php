<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LogsConsoleOutput;
use App\Services\Activity\EngagementAggregator;
use Illuminate\Console\Command;

class AggregateEngagementCommand extends Command
{
    use LogsConsoleOutput;

    protected $signature = 'eventpulse:aggregate-engagement';

    protected $description = 'Recompute each event\'s behavioural engagement score from the activity log';

    public function handle(EngagementAggregator $aggregator): int
    {
        $this->info('Recomputing engagement scores...');

        $count = $aggregator->recompute();

        $this->info("Scored {$count} events.");

        return self::SUCCESS;
    }
}
