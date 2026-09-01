<?php

declare(strict_types=1);

use App\Enums\ActivityType;
use App\Enums\EventCategory;
use App\Enums\Reaction;
use App\Models\Event;
use App\Models\EventBookmark;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Models\UserEventReaction;
use App\Services\Profile\ProfileActivitySummarizer;

function summarizeProfileActivity(User $user): array
{
    return app(ProfileActivitySummarizer::class)->build($user);
}

function eventForActivitySummary(EventCategory $category): Event
{
    return Event::withoutSyncingToSearch(
        fn () => Event::factory()->create(['category' => $category])
    );
}

it('counts reactions by type and saves separately', function () {
    $user = User::factory()->create();

    UserEventReaction::factory()->count(3)->create([
        'user_id' => $user->id,
        'reaction' => Reaction::Interested,
    ]);
    UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'reaction' => Reaction::NotInterested,
    ]);
    EventBookmark::factory()->count(2)->create(['user_id' => $user->id]);

    $summary = summarizeProfileActivity($user);

    expect($summary['reactions'])->toBe(['interested' => 3, 'not_interested' => 1]);
    expect($summary['saved'])->toBe(2);
    expect($summary['has_activity'])->toBeTrue();
});

it('ranks the categories behind the positive reactions', function () {
    $user = User::factory()->create();

    foreach (range(1, 3) as $ignored) {
        UserEventReaction::factory()->create([
            'user_id' => $user->id,
            'event_id' => eventForActivitySummary(EventCategory::Music)->id,
            'reaction' => Reaction::Interested,
        ]);
    }

    UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => eventForActivitySummary(EventCategory::Film)->id,
        'reaction' => Reaction::Interested,
    ]);

    // A dislike says nothing about what the user likes, so it must not rank.
    UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => eventForActivitySummary(EventCategory::Sports)->id,
        'reaction' => Reaction::NotInterested,
    ]);

    expect(summarizeProfileActivity($user)['top_categories'])->toBe([
        ['category' => 'music', 'count' => 3],
        ['category' => 'film', 'count' => 1],
    ]);
});

it('lists the most recent reactions newest first, with their event titles', function () {
    $user = User::factory()->create();
    $older = eventForActivitySummary(EventCategory::Arts);
    $newer = eventForActivitySummary(EventCategory::Music);

    UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $older->id,
        'reaction' => Reaction::NotInterested,
        'created_at' => now()->subDays(2),
    ]);
    UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $newer->id,
        'reaction' => Reaction::Interested,
        'created_at' => now()->subHour(),
    ]);

    $recent = summarizeProfileActivity($user)['recent'];

    expect($recent)->toHaveCount(2);
    expect($recent[0]['event_title'])->toBe($newer->title);
    expect($recent[0]['reaction'])->toBe('interested');
    expect($recent[1]['event_title'])->toBe($older->title);
});

it('counts implicit activity within the retention window only', function () {
    config(['eventpulse.activity.retention_days' => 30]);
    $user = User::factory()->create();

    UserActivityLog::factory()->count(2)->create([
        'user_id' => $user->id,
        'type' => ActivityType::EventView,
    ]);
    UserActivityLog::factory()->create([
        'user_id' => $user->id,
        'type' => ActivityType::Search,
    ]);
    // Past the window — PruneActivityLogsJob would have dropped this row, so
    // counting it would promise a lifetime total the table cannot back.
    UserActivityLog::factory()->create([
        'user_id' => $user->id,
        'type' => ActivityType::EventView,
        'created_at' => now()->subDays(45),
    ]);
    // A mail scanner's hit is not the user's activity.
    UserActivityLog::factory()->create([
        'user_id' => $user->id,
        'type' => ActivityType::EventView,
        'is_bot' => true,
    ]);

    $summary = summarizeProfileActivity($user);

    expect($summary['implicit'])->toBe([
        'event_view' => 2,
        'event_click' => 0,
        'calendar_download' => 0,
        'search' => 1,
    ]);
    expect($summary['implicit_window_days'])->toBe(30);
});

it('never counts another user\'s activity', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();

    UserEventReaction::factory()->create([
        'user_id' => $stranger->id,
        'reaction' => Reaction::Interested,
    ]);
    EventBookmark::factory()->create(['user_id' => $stranger->id]);
    UserActivityLog::factory()->create([
        'user_id' => $stranger->id,
        'type' => ActivityType::EventView,
    ]);

    $summary = summarizeProfileActivity($user);

    expect($summary['reactions'])->toBe(['interested' => 0, 'not_interested' => 0]);
    expect($summary['saved'])->toBe(0);
    expect($summary['recent'])->toBe([]);
    expect($summary['implicit']['event_view'])->toBe(0);
});

it('reports no activity for a fresh account', function () {
    $summary = summarizeProfileActivity(User::factory()->create());

    expect($summary['has_activity'])->toBeFalse();
    expect($summary['top_categories'])->toBe([]);
    expect($summary['recent'])->toBe([]);
});
