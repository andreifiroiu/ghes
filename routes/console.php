<?php

use App\Jobs\ApplyPassiveDecayJob;
use App\Jobs\CleanupExpiredEventsJob;
use App\Jobs\PruneActivityLogsJob;
use Illuminate\Support\Facades\Schedule;

Schedule::command('eventpulse:scrape')
    ->everyFourHours()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scraper.log'));

Schedule::command('eventpulse:process-events')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Heals duplicates that slipped past the write-time matcher, e.g. when two
// workers created a canonical row for the same event at the same instant.
Schedule::command('eventpulse:dedupe-events --fuzzy')
    ->dailyAt('05:00')
    ->withoutOverlapping();

Schedule::command('eventpulse:send-notifications')
    ->dailyAt(sprintf('%02d:00', config('eventpulse.notifications.hour', 8)))
    ->withoutOverlapping();

Schedule::command('eventpulse:decay-profiles')
    ->weekly()
    ->withoutOverlapping();

// Before the notification run, so the digest ranks on engagement that includes
// yesterday's clicks rather than the day before's.
Schedule::command('eventpulse:aggregate-engagement')
    ->dailyAt('06:00')
    ->withoutOverlapping();

Schedule::job(new CleanupExpiredEventsJob)
    ->dailyAt('04:00')
    ->withoutOverlapping();

Schedule::job(new ApplyPassiveDecayJob)
    ->dailyAt('03:30')
    ->withoutOverlapping();

Schedule::job(new PruneActivityLogsJob)
    ->weeklyOn(1, '04:30')
    ->withoutOverlapping();

Schedule::command('horizon:snapshot')
    ->everyFiveMinutes();
