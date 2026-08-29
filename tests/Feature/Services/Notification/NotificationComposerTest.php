<?php

declare(strict_types=1);

use App\Enums\EventCategory;
use App\Enums\NotificationFrequency;
use App\Models\Event;
use App\Models\Notification;
use App\Models\User;
use App\Services\InterestProfile\ProfileScorer;
use App\Services\Notification\NotificationComposer;
use App\Services\Recommendation\DiscoveryEngine;
use App\Services\Recommendation\DiversityFilter;
use App\Services\Recommendation\ExperimentAssigner;
use App\Services\Recommendation\RecommendationEngine;

function makeComposer(): NotificationComposer
{
    return new NotificationComposer(
        recommendationEngine: new RecommendationEngine(
            profileScorer: new ProfileScorer,
            discoveryEngine: new DiscoveryEngine,
            diversityFilter: new DiversityFilter,
            experimentAssigner: new ExperimentAssigner,
        ),
    );
}

it('composes a notification with event IDs for a user', function () {
    $user = User::factory()->create([
        'interest_profile' => ['music' => 0.8],
        'city' => 'Bucharest',
        'onboarding_completed' => true,
    ]);

    Event::factory()->count(5)->create([
        'category' => EventCategory::Music,
        'city' => 'Bucharest',
        'starts_at' => now()->addDays(3),
        'is_classified' => true,
    ]);

    $notification = makeComposer()->compose($user);

    expect($notification)->not->toBeNull();
    expect($notification->user_id)->toBe($user->id);
    expect($notification->event_ids)->not->toBeEmpty();
    expect($notification->subject)->toContain('Ghes');
    expect($notification)->toBeInstanceOf(Notification::class);
    $this->assertModelExists($notification);
});

it('returns null when no events are available', function () {
    $user = User::factory()->create([
        'interest_profile' => ['music' => 0.8],
        'city' => 'NonexistentCity',
        'onboarding_completed' => true,
    ]);

    $notification = makeComposer()->compose($user);

    expect($notification)->toBeNull();
});

it('includes discovery event IDs in the notification', function () {
    $user = User::factory()->create([
        'interest_profile' => ['music' => 0.95],
        'city' => 'Bucharest',
        'onboarding_completed' => true,
    ]);

    // Events in a category the user doesn't prefer (for discovery)
    Event::factory()->count(3)->create([
        'category' => EventCategory::Technology,
        'city' => 'Bucharest',
        'starts_at' => now()->addDays(5),
        'is_classified' => true,
    ]);

    // Events in user's preferred category
    Event::factory()->count(8)->create([
        'category' => EventCategory::Music,
        'city' => 'Bucharest',
        'starts_at' => now()->addDays(5),
        'is_classified' => true,
    ]);

    $notification = makeComposer()->compose($user);

    expect($notification)->not->toBeNull();
    expect($notification->discovery_event_ids)->not->toBeEmpty();
});

it('composes for all due users', function () {
    // User who has never been notified
    User::factory()->create([
        'interest_profile' => ['music' => 0.8],
        'city' => 'Bucharest',
        'onboarding_completed' => true,
        'notification_frequency' => NotificationFrequency::Daily,
    ]);

    // User who was notified 2 days ago (daily = due)
    $dueDailyUser = User::factory()->create([
        'interest_profile' => ['music' => 0.8],
        'city' => 'Bucharest',
        'onboarding_completed' => true,
        'notification_frequency' => NotificationFrequency::Daily,
    ]);
    Notification::factory()->create([
        'user_id' => $dueDailyUser->id,
        'sent_at' => now()->subDays(2),
    ]);

    // User who was notified 1 hour ago (daily = not due)
    $recentUser = User::factory()->create([
        'interest_profile' => ['music' => 0.8],
        'city' => 'Bucharest',
        'onboarding_completed' => true,
        'notification_frequency' => NotificationFrequency::Daily,
    ]);
    Notification::factory()->create([
        'user_id' => $recentUser->id,
        'sent_at' => now()->subHour(),
    ]);

    // User not onboarded
    User::factory()->create([
        'onboarding_completed' => false,
    ]);

    Event::factory()->count(10)->create([
        'city' => 'Bucharest',
        'starts_at' => now()->addDays(3),
        'is_classified' => true,
    ]);

    $notifications = makeComposer()->composeForAll();

    // 2 due users (never notified + 2 days ago), 1 not due, 1 not onboarded
    expect($notifications->count())->toBe(2);
});

it('respects weekly frequency', function () {
    $weeklyUser = User::factory()->create([
        'interest_profile' => ['music' => 0.8],
        'city' => 'Bucharest',
        'onboarding_completed' => true,
        'notification_frequency' => NotificationFrequency::Weekly,
    ]);

    // Notified 3 days ago — not due for weekly
    Notification::factory()->create([
        'user_id' => $weeklyUser->id,
        'sent_at' => now()->subDays(3),
    ]);

    Event::factory()->count(5)->create([
        'city' => 'Bucharest',
        'starts_at' => now()->addDays(3),
        'is_classified' => true,
    ]);

    $notifications = makeComposer()->composeForAll();

    expect($notifications)->toBeEmpty();
});
