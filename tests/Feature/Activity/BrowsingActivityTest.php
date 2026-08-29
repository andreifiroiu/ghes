<?php

declare(strict_types=1);

use App\Enums\ActivitySurface;
use App\Enums\ActivityType;
use App\Models\Event;
use App\Models\User;
use App\Models\UserActivityLog;

beforeEach(function () {
    $this->withoutVite();
});

it('logs an impression for every event the browse page rendered', function () {
    $events = Event::factory()->count(3)->create(['starts_at' => now()->addWeek()]);

    $this->get('/events')->assertOk();

    $impressions = UserActivityLog::ofType(ActivityType::EventImpression)->get();

    expect($impressions)->toHaveCount(3)
        ->and($impressions->pluck('event_id')->sort()->values()->all())
        ->toBe($events->pluck('id')->sort()->values()->all())
        ->and($impressions->first()->surface)->toBe(ActivitySurface::EventsIndex);
});

it('logs a view when an event detail page is opened', function () {
    $event = Event::factory()->create();

    $this->get("/events/{$event->id}")->assertOk();

    $log = UserActivityLog::ofType(ActivityType::EventView)->sole();

    expect($log->event_id)->toBe($event->id)
        ->and($log->surface)->toBe(ActivitySurface::EventDetail);
});

it('logs a search row carrying the filters that were applied', function () {
    Event::factory()->create(['starts_at' => now()->addWeek()]);

    $this->get('/events?category=music&city=Timi%C8%99oara')->assertOk();

    $log = UserActivityLog::ofType(ActivityType::Search)->sole();

    // Canonicalizing, not toBe(): `context` is jsonb, and Postgres normalises
    // key order on the way out while sqlite preserves insertion order. An
    // order-sensitive assertion here is green in the suite and red in production.
    expect($log->context['filters'])->toEqualCanonicalizing(['category' => 'music', 'city' => 'Timișoara']);
});

it('does not log a search for an unfiltered browse', function () {
    Event::factory()->create(['starts_at' => now()->addWeek()]);

    $this->get('/events')->assertOk();

    // Otherwise the "what are people looking for" report is mostly blanks.
    expect(UserActivityLog::ofType(ActivityType::Search)->count())->toBe(0);
});

it('ignores an empty filter value', function () {
    Event::factory()->create(['starts_at' => now()->addWeek()]);

    $this->get('/events?search=')->assertOk();

    expect(UserActivityLog::ofType(ActivityType::Search)->count())->toBe(0);
});

it('attributes browsing to a signed-in user', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    $this->actingAs($user)->get("/events/{$event->id}")->assertOk();

    expect(UserActivityLog::ofType(ActivityType::EventView)->sole()->user_id)->toBe($user->id);
});

it('records a guest browse anonymously', function () {
    $event = Event::factory()->create();

    // Event browsing is public, so a null user_id is a normal row, not a bug.
    // It still counts toward the event's popularity; it just cannot feed a
    // profile, because there is no profile to feed.
    $this->get("/events/{$event->id}")->assertOk();

    expect(UserActivityLog::ofType(ActivityType::EventView)->sole()->user_id)->toBeNull();
});

it('logs the impression against the canonical event after a merge', function () {
    $canonical = Event::factory()->create();
    $duplicate = Event::factory()->create(['merged_into_id' => $canonical->id]);

    $this->get("/events/{$duplicate->id}")->assertOk();

    expect(UserActivityLog::ofType(ActivityType::EventView)->sole()->event_id)->toBe($canonical->id);
});

it('logs dashboard impressions under the dashboard surface', function () {
    $user = User::factory()->create(['onboarding_completed' => true]);
    Event::factory()->count(2)->create(['starts_at' => now()->addWeek()]);

    $this->actingAs($user)->get('/dashboard')->assertOk();

    $impressions = UserActivityLog::ofType(ActivityType::EventImpression)->get();

    expect($impressions)->not->toBeEmpty()
        ->and($impressions->pluck('surface')->unique()->all())->toBe([ActivitySurface::Dashboard]);
});

it('does not record a filter the query silently discarded', function () {
    Event::factory()->create(['starts_at' => now()->addWeek()]);

    // browseQuery() swallows an unparseable date and falls back to all upcoming
    // events. Recording it would have the analytics attribute an unfiltered
    // result count to a filter that never ran.
    $this->get('/events?date=not-a-date')->assertOk();

    expect(UserActivityLog::ofType(ActivityType::Search)->count())->toBe(0);
});

it('does not record a range value the query does not honour', function () {
    Event::factory()->create(['starts_at' => now()->addWeek()]);

    // Only `weekend` is applied; anything else is ignored by the query.
    $this->get('/events?range=nextweek')->assertOk();

    expect(UserActivityLog::ofType(ActivityType::Search)->count())->toBe(0);
});

it('records the normalised date a filter actually applied', function () {
    Event::factory()->create(['starts_at' => now()->addWeek()]);

    $this->get('/events?date=2026-09-15')->assertOk();

    expect(UserActivityLog::ofType(ActivityType::Search)->sole()->context['filters'])
        ->toEqualCanonicalizing(['date' => '2026-09-15']);
});

it('records the applied filters alongside a discarded one', function () {
    Event::factory()->create(['starts_at' => now()->addWeek()]);

    $this->get('/events?category=music&date=not-a-date')->assertOk();

    expect(UserActivityLog::ofType(ActivityType::Search)->sole()->context['filters'])
        ->toEqualCanonicalizing(['category' => 'music']);
});
