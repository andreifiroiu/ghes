<?php

declare(strict_types=1);

use App\Enums\EventCategory;
use App\Enums\Reaction;
use App\Models\DiscoveryLog;
use App\Models\Event;
use App\Models\User;
use App\Models\UserEventReaction;
use App\Services\Recommendation\DiscoveryEngine;

beforeEach(function () {
    $this->engine = new DiscoveryEngine;
});

it('discovers events from categories with low user scores', function () {
    $user = User::factory()->create([
        'interest_profile' => [
            'music' => 0.9,
            'sports' => 0.85,
            'technology' => 0.1,
            'arts' => 0.0,
        ],
    ]);

    Event::factory()->create([
        'category' => EventCategory::Technology,
        'starts_at' => now()->addDays(5),
        'is_classified' => true,
    ]);
    Event::factory()->create([
        'category' => EventCategory::Arts,
        'starts_at' => now()->addDays(5),
        'is_classified' => true,
    ]);

    $discoveries = $this->engine->discoverForUser($user, 2);

    expect($discoveries)->toHaveCount(2);
    $categories = $discoveries->pluck('category')->map->value->toArray();
    expect($categories)->each->not->toBe('music');
    expect($categories)->each->not->toBe('sports');
});

it('excludes events carrying the user negative tags', function () {
    $user = User::factory()->create([
        'interest_profile' => ['music' => 0.95, 'negtag:crowded' => 1.0],
    ]);

    $blocked = Event::factory()->create([
        'category' => EventCategory::Technology,
        'tags' => ['crowded'],
        'starts_at' => now()->addDays(4),
        'is_classified' => true,
    ]);
    $allowed = Event::factory()->create([
        'category' => EventCategory::Technology,
        'tags' => ['hands-on'],
        'starts_at' => now()->addDays(4),
        'is_classified' => true,
    ]);

    $discoveries = $this->engine->discoverForUser($user, 3);

    expect($discoveries->pluck('id'))->not->toContain($blocked->id);
});

it('respects the requested count', function () {
    $user = User::factory()->create(['interest_profile' => ['music' => 0.9]]);

    Event::factory()->count(10)->create([
        'category' => EventCategory::Technology,
        'starts_at' => now()->addDays(5),
        'is_classified' => true,
    ]);

    $discoveries = $this->engine->discoverForUser($user, 3);

    expect($discoveries->count())->toBeLessThanOrEqual(3);
});

it('excludes events the user already reacted to', function () {
    $user = User::factory()->create(['interest_profile' => ['music' => 0.95]]);

    $reactedEvent = Event::factory()->create([
        'category' => EventCategory::Technology,
        'starts_at' => now()->addDays(5),
        'is_classified' => true,
    ]);

    UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $reactedEvent->id,
        'reaction' => Reaction::NotInterested,
    ]);

    $fresh = Event::factory()->create([
        'category' => EventCategory::Technology,
        'starts_at' => now()->addDays(5),
        'is_classified' => true,
    ]);

    $discoveries = $this->engine->discoverForUser($user, 5);

    expect($discoveries->pluck('id'))->not->toContain($reactedEvent->id);
});

it('logs discovery events to discovery_logs', function () {
    $user = User::factory()->create(['interest_profile' => ['music' => 0.95]]);

    Event::factory()->create([
        'category' => EventCategory::Arts,
        'starts_at' => now()->addDays(5),
        'is_classified' => true,
    ]);

    $this->engine->discoverForUser($user, 1);

    expect(DiscoveryLog::where('user_id', $user->id)->count())->toBe(1);

    $log = DiscoveryLog::where('user_id', $user->id)->first();
    expect($log->category_explored)->toBe('arts');
    expect($log->surprise_score)->toBeGreaterThan(0.0);
});

it('calculates surprise score as inverse of profile score', function () {
    $user = User::factory()->create([
        'interest_profile' => ['music' => 0.8, 'technology' => 0.2],
    ]);

    $musicEvent = Event::factory()->create(['category' => EventCategory::Music]);
    $techEvent = Event::factory()->create(['category' => EventCategory::Technology]);

    expect($this->engine->calculateSurpriseScore($user, $musicEvent))->toEqualWithDelta(0.2, 0.0001);
    expect($this->engine->calculateSurpriseScore($user, $techEvent))->toEqualWithDelta(0.8, 0.0001);
});

it('returns 1.0 surprise for categories not in profile', function () {
    $user = User::factory()->create(['interest_profile' => []]);
    $event = Event::factory()->create(['category' => EventCategory::Film]);

    expect($this->engine->calculateSurpriseScore($user, $event))->toBe(1.0);
});

it('returns empty collection when user has high scores everywhere', function () {
    $profile = [];
    foreach (EventCategory::cases() as $cat) {
        $profile[$cat->value] = 0.95; // 1 - 0.95 = 0.05 < 0.3 min surprise
    }

    $user = User::factory()->create(['interest_profile' => $profile]);

    Event::factory()->count(5)->create([
        'starts_at' => now()->addDays(5),
        'is_classified' => true,
    ]);

    $discoveries = $this->engine->discoverForUser($user, 3);

    expect($discoveries)->toBeEmpty();
});

// ---------------------------------------------------------------
// Serendipity suppression
// ---------------------------------------------------------------

it('suppresses a category surfaced repeatedly with no positive outcome', function () {
    $user = User::factory()->create();

    foreach (range(1, 3) as $i) {
        $event = Event::factory()->create(['category' => EventCategory::Technology]);
        DiscoveryLog::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'category_explored' => 'technology',
            'surprise_score' => 0.9,
            'outcome' => 'ignored',
        ]);
    }

    expect($this->engine->suppressedCategories($user))->toContain('technology');
});

it('does not suppress a category that received a positive outcome', function () {
    $user = User::factory()->create();

    foreach (['ignored', 'ignored', 'interested'] as $outcome) {
        $event = Event::factory()->create(['category' => EventCategory::Technology]);
        DiscoveryLog::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'category_explored' => 'technology',
            'surprise_score' => 0.9,
            'outcome' => $outcome,
        ]);
    }

    expect($this->engine->suppressedCategories($user))->not->toContain('technology');
});

it('skips suppressed categories during discovery', function () {
    $user = User::factory()->create(['interest_profile' => []]);

    foreach (range(1, 3) as $i) {
        $seed = Event::factory()->create(['category' => EventCategory::Technology]);
        DiscoveryLog::create([
            'user_id' => $user->id,
            'event_id' => $seed->id,
            'category_explored' => 'technology',
            'surprise_score' => 0.9,
            'outcome' => 'ignored',
        ]);
    }

    $tech = Event::factory()->create([
        'category' => EventCategory::Technology,
        'starts_at' => now()->addDays(3),
        'is_classified' => true,
    ]);
    $arts = Event::factory()->create([
        'category' => EventCategory::Arts,
        'starts_at' => now()->addDays(3),
        'is_classified' => true,
    ]);

    $discoveries = $this->engine->discoverForUser($user, 5);

    expect($discoveries->pluck('id'))->not->toContain($tech->id)
        ->and($discoveries->pluck('id'))->toContain($arts->id);
});

// ---------------------------------------------------------------
// Trending injection
// ---------------------------------------------------------------

it('injects platform-wide trending events regardless of profile', function () {
    $profile = [];
    foreach (EventCategory::cases() as $cat) {
        $profile[$cat->value] = 0.95; // no low-score categories
    }
    $user = User::factory()->create(['interest_profile' => $profile]);

    $trendingEvent = Event::factory()->create([
        'category' => EventCategory::Music,
        'starts_at' => now()->addDays(3),
        'is_classified' => true,
    ]);

    foreach (range(1, 3) as $i) {
        UserEventReaction::factory()->create([
            'event_id' => $trendingEvent->id,
            'reaction' => Reaction::Interested,
        ]);
    }

    $discoveries = $this->engine->discoverForUser($user, 2);

    expect($discoveries->pluck('id'))->toContain($trendingEvent->id);
});

// ---------------------------------------------------------------
// Collaborative filtering
// ---------------------------------------------------------------

it('biases discovery toward categories popular among similar users', function () {
    // Current user likes music; every other category is low-score.
    $user = User::factory()->create(['interest_profile' => ['music' => 0.9]]);

    // A similar user (also likes music) reacted positively to a Technology event.
    $similar = User::factory()->create(['interest_profile' => ['music' => 0.9]]);
    $likedTech = Event::factory()->create(['category' => EventCategory::Technology]);
    UserEventReaction::factory()->create([
        'user_id' => $similar->id,
        'event_id' => $likedTech->id,
        'reaction' => Reaction::Interested,
    ]);

    // Discovery candidates: one Technology (collaborative) and one Arts (not).
    Event::factory()->create([
        'category' => EventCategory::Technology,
        'starts_at' => now()->addDays(3),
        'is_classified' => true,
    ]);
    Event::factory()->create([
        'category' => EventCategory::Arts,
        'starts_at' => now()->addDays(3),
        'is_classified' => true,
    ]);

    $discoveries = $this->engine->discoverForUser($user, 1);

    expect($discoveries->pluck('category')->map->value)->toContain('technology');
});

it('returns no collaborative categories when there are no similar users', function () {
    $user = User::factory()->create(['interest_profile' => ['music' => 0.9]]);

    expect($this->engine->collaborativelyPopularCategories($user))->toBe([]);
});

// ---------------------------------------------------------------
// discovery_openness auto-tuning
// ---------------------------------------------------------------

it('lowers discovery openness when the hit rate is poor', function () {
    $user = User::factory()->create(['discovery_openness' => 0.5]);

    foreach (range(1, 6) as $i) {
        $event = Event::factory()->create();
        DiscoveryLog::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'category_explored' => 'music',
            'surprise_score' => 0.5,
            'outcome' => 'ignored',
        ]);
    }

    $this->engine->recalibrateOpenness($user);

    $user->refresh();
    expect((float) $user->discovery_openness)->toEqualWithDelta(0.45, 0.0001);
});

it('does not change openness below the minimum sample size', function () {
    $user = User::factory()->create(['discovery_openness' => 0.5]);

    foreach (range(1, 2) as $i) {
        $event = Event::factory()->create();
        DiscoveryLog::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'category_explored' => 'music',
            'surprise_score' => 0.5,
            'outcome' => 'ignored',
        ]);
    }

    $this->engine->recalibrateOpenness($user);

    $user->refresh();
    expect((float) $user->discovery_openness)->toEqualWithDelta(0.5, 0.0001);
});

it('does not change openness when the hit rate is healthy', function () {
    $user = User::factory()->create(['discovery_openness' => 0.5]);

    foreach (range(1, 6) as $i) {
        $event = Event::factory()->create();
        DiscoveryLog::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'category_explored' => 'music',
            'surprise_score' => 0.5,
            'outcome' => $i <= 3 ? 'interested' : 'ignored',
        ]);
    }

    $this->engine->recalibrateOpenness($user);

    $user->refresh();
    expect((float) $user->discovery_openness)->toEqualWithDelta(0.5, 0.0001);
});
