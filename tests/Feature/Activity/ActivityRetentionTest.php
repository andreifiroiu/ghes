<?php

declare(strict_types=1);

use App\Enums\ActivityType;
use App\Jobs\PruneActivityLogsJob;
use App\Models\Event;
use App\Models\UserActivityLog;
use App\Services\Processing\EventMerger;

it('prunes activity past the retention window and keeps the rest', function () {
    config(['eventpulse.activity.retention_days' => 90]);

    $old = UserActivityLog::factory()->create(['created_at' => now()->subDays(120)]);
    $recent = UserActivityLog::factory()->create(['created_at' => now()->subDays(30)]);

    (new PruneActivityLogsJob)->handle();

    expect(UserActivityLog::find($old->id))->toBeNull()
        ->and(UserActivityLog::find($recent->id))->not->toBeNull();
});

it('keeps the engagement score a prune erases the evidence for', function () {
    config(['eventpulse.activity.retention_days' => 90]);

    $event = Event::factory()->create(['engagement_score' => 60]);
    UserActivityLog::factory()->create([
        'event_id' => $event->id,
        'created_at' => now()->subDays(120),
    ]);

    (new PruneActivityLogsJob)->handle();

    // The derived score outlives the raw rows on purpose — pruning must not
    // make the catalogue forget what those rows taught it.
    expect($event->fresh()->engagement_score)->toBe(60);
});

it('moves activity onto the canonical event when a duplicate is merged', function () {
    $canonical = Event::factory()->create();
    $duplicate = Event::factory()->create();

    UserActivityLog::factory()->count(3)->create([
        'event_id' => $duplicate->id,
        'type' => ActivityType::EventClick,
    ]);

    app(EventMerger::class)->mergeInto($canonical, $duplicate, syncSearch: false);

    // Left behind, an event's engagement would split across however many copies
    // of it we happened to scrape, and each half would rank as unpopular.
    expect(UserActivityLog::where('event_id', $canonical->id)->count())->toBe(3)
        ->and(UserActivityLog::where('event_id', $duplicate->id)->count())->toBe(0);
});
