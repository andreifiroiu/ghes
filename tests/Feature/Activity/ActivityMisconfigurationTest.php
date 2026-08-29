<?php

declare(strict_types=1);

use App\Enums\ActivityType;
use App\Jobs\PruneActivityLogsJob;
use App\Models\Event;
use App\Models\UserActivityLog;
use App\Services\Activity\EngagementAggregator;

/**
 * Guards against the two ways a blank or mistyped env var silently destroys
 * data. `env('X')` returns '' for a key that is present but empty, `(int) ''`
 * is 0, and config()'s default cannot rescue it because the key does exist.
 */
it('refuses to prune when the retention window is not a positive number', function (mixed $configured) {
    config(['eventpulse.activity.retention_days' => $configured]);

    UserActivityLog::factory()->count(3)->create(['created_at' => now()->subDays(400)]);

    (new PruneActivityLogsJob)->handle();

    // A zero-day cutoff is *now*, which would delete the entire log including
    // rows written seconds ago — and report a successful prune.
    expect(UserActivityLog::count())->toBe(3);
})->with([
    'blank env var' => '',
    'zero' => 0,
    'negative' => -1,
    'non-numeric' => 'six months',
]);

it('still prunes normally with a sane retention window', function () {
    config(['eventpulse.activity.retention_days' => 90]);

    UserActivityLog::factory()->create(['created_at' => now()->subDays(400)]);
    UserActivityLog::factory()->create(['created_at' => now()->subDays(10)]);

    (new PruneActivityLogsJob)->handle();

    expect(UserActivityLog::count())->toBe(1);
});

it('refuses to zero every engagement score when activity exists but the window is empty', function () {
    $event = Event::factory()->create(['engagement_score' => 70]);

    UserActivityLog::factory()->count(4)->create([
        'event_id' => $event->id,
        'type' => ActivityType::EventClick,
        'created_at' => now()->subDays(5),
    ]);

    // A blank EVENTPULSE_ENGAGEMENT_WINDOW_DAYS would cast to 0 and match
    // nothing, wiping the catalogue while the command reported success. The
    // floor keeps it at a day, so the five-day-old activity still falls
    // outside — which is the case that must refuse rather than wipe.
    config(['eventpulse.activity.engagement_window_days' => '']);

    app(EngagementAggregator::class)->recompute();

    expect($event->fresh()->engagement_score)->toBe(70);
});

it('zeroes scores normally when there is genuinely no activity', function () {
    $event = Event::factory()->create(['engagement_score' => 70]);

    // Nothing in the table at all — a real quiet period, not a misconfiguration.
    $scored = app(EngagementAggregator::class)->recompute();

    expect($scored)->toBe(0)
        ->and($event->fresh()->engagement_score)->toBe(0);
});
