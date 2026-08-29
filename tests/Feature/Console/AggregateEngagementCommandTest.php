<?php

declare(strict_types=1);

use App\Enums\ActivityType;
use App\Models\Event;
use App\Models\UserActivityLog;

function seedActivity(Event $event, ActivityType $type, int $times, array $state = []): void
{
    UserActivityLog::factory()->count($times)->create([
        'event_id' => $event->id,
        'type' => $type,
        ...$state,
    ]);
}

beforeEach(function () {
    // A ceiling of 10 keeps the arithmetic in these tests legible.
    config(['eventpulse.activity.engagement_ceiling' => 10]);
});

it('rolls weighted activity up into an engagement score', function () {
    $event = Event::factory()->create();

    // 2 clicks (3.0 each) = 6.0 against a ceiling of 10 → 60.
    seedActivity($event, ActivityType::EventClick, 2);

    $this->artisan('eventpulse:aggregate-engagement')->assertSuccessful();

    expect($event->fresh()->engagement_score)->toBe(60);
});

it('weighs a bookmark more heavily than a view', function () {
    $saved = Event::factory()->create();
    $viewed = Event::factory()->create();

    seedActivity($saved, ActivityType::BookmarkAdded, 1);
    seedActivity($viewed, ActivityType::EventView, 1);

    $this->artisan('eventpulse:aggregate-engagement');

    expect($saved->fresh()->engagement_score)->toBeGreaterThan($viewed->fresh()->engagement_score);
});

it('caps at 100', function () {
    $event = Event::factory()->create();

    seedActivity($event, ActivityType::BookmarkAdded, 50);

    $this->artisan('eventpulse:aggregate-engagement');

    expect($event->fresh()->engagement_score)->toBe(100);
});

it('floors a disliked event at zero rather than below unseen ones', function () {
    $disliked = Event::factory()->create();

    seedActivity($disliked, ActivityType::ReactionNotInterested, 5);

    $this->artisan('eventpulse:aggregate-engagement');

    expect($disliked->fresh()->engagement_score)->toBe(0);
});

it('ignores impressions, which say nothing about an event on their own', function () {
    $event = Event::factory()->create();

    seedActivity($event, ActivityType::EventImpression, 40);

    $this->artisan('eventpulse:aggregate-engagement');

    expect($event->fresh()->engagement_score)->toBe(0);
});

it('excludes bot traffic', function () {
    $event = Event::factory()->create();

    seedActivity($event, ActivityType::EventClick, 10, ['is_bot' => true]);

    $this->artisan('eventpulse:aggregate-engagement');

    expect($event->fresh()->engagement_score)->toBe(0);
});

it('ignores activity older than the window', function () {
    config(['eventpulse.activity.engagement_window_days' => 30]);

    $event = Event::factory()->create();

    seedActivity($event, ActivityType::EventClick, 4, ['created_at' => now()->subDays(90)]);

    $this->artisan('eventpulse:aggregate-engagement');

    expect($event->fresh()->engagement_score)->toBe(0);
});

it('lets a score fall when the activity behind it ages out', function () {
    $event = Event::factory()->create(['engagement_score' => 90]);

    // Nothing recent to justify the old score. Ranking has to be able to
    // forget, or last month's hit stays on top forever.
    $this->artisan('eventpulse:aggregate-engagement');

    expect($event->fresh()->engagement_score)->toBe(0);
});
