<?php

use App\Jobs\ApplyPassiveDecayJob;
use App\Jobs\CleanupExpiredEventsJob;
use Illuminate\Support\Facades\Schedule;

Schedule::command('eventpulse:scrape')
    ->everyFourHours()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scraper.log'));

Schedule::command('eventpulse:process-events')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::command('eventpulse:send-notifications')
    ->dailyAt(sprintf('%02d:00', config('eventpulse.notifications.hour', 8)))
    ->withoutOverlapping();

Schedule::command('eventpulse:decay-profiles')
    ->weekly()
    ->withoutOverlapping();

Schedule::job(new CleanupExpiredEventsJob)
    ->dailyAt('04:00')
    ->withoutOverlapping();

Schedule::job(new ApplyPassiveDecayJob)
    ->dailyAt('03:30')
    ->withoutOverlapping();

Schedule::command('horizon:snapshot')
    ->everyFiveMinutes();
