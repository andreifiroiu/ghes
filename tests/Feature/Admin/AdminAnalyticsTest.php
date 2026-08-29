<?php

declare(strict_types=1);

use App\Enums\ActivityType;
use App\Enums\NotificationChannel;
use App\Models\Event;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\Activity\ActivityReporter;

beforeEach(function () {
    $this->withoutVite();

    $this->reporter = app(ActivityReporter::class);
});

it('forbids non-admins', function () {
    $this->actingAs(User::factory()->create())->get('/admin/analytics')->assertStatus(403);
});

it('renders for an admin', function () {
    $admin = User::factory()->create();
    config(['eventpulse.admin_emails' => [$admin->email]]);

    $this->actingAs($admin)->get('/admin/analytics')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Analytics')->has('summary'));
});

it('falls back to the default window for an unsupported one', function () {
    $admin = User::factory()->create();
    config(['eventpulse.admin_emails' => [$admin->email]]);

    $this->actingAs($admin)->get('/admin/analytics?window=9999')
        ->assertInertia(fn ($page) => $page->where('summary.window_days', 30));
});

it('computes click-through rate from impressions and clicks', function () {
    $event = Event::factory()->create();

    UserActivityLog::factory()->count(10)->create([
        'event_id' => $event->id,
        'type' => ActivityType::EventImpression,
    ]);
    UserActivityLog::factory()->count(2)->create([
        'event_id' => $event->id,
        'type' => ActivityType::EventClick,
    ]);

    expect($this->reporter->summary()['click_through_rate'])->toBe(0.2);
});

it('excludes bot traffic from the click-through rate', function () {
    $event = Event::factory()->create();

    UserActivityLog::factory()->count(10)->create([
        'event_id' => $event->id,
        'type' => ActivityType::EventImpression,
    ]);
    UserActivityLog::factory()->count(8)->bot()->create([
        'event_id' => $event->id,
        'type' => ActivityType::EventClick,
    ]);

    // A scanner that fetches every link would otherwise report a near-perfect
    // rate, and someone would believe it.
    expect($this->reporter->summary()['click_through_rate'])->toBe(0.0);
});

it('reports a zero rate rather than dividing by zero', function () {
    expect($this->reporter->summary()['click_through_rate'])->toBe(0.0)
        ->and($this->reporter->summary()['digest']['open_rate'])->toBe(0.0);
});

it('counts only emailable digests in the open rate', function () {
    $user = User::factory()->create();

    Notification::factory()->for($user)->create([
        'channel' => NotificationChannel::Email,
        'sent_at' => now(),
        'opened_at' => now(),
    ]);
    // A push-only digest can never report an open — no email, no pixel — so
    // including it would depress the rate for a reason unrelated to the email.
    Notification::factory()->for($user)->create([
        'channel' => NotificationChannel::Push,
        'sent_at' => now(),
        'opened_at' => null,
    ]);

    $digest = $this->reporter->summary()['digest'];

    expect($digest['sent'])->toBe(1)
        ->and($digest['opened'])->toBe(1)
        ->and($digest['open_rate'])->toBe(1.0);
});

it('ranks the most clicked events with their impressions', function () {
    $popular = Event::factory()->create(['title' => 'Concert popular']);
    $quiet = Event::factory()->create(['title' => 'Concert liniștit']);

    UserActivityLog::factory()->count(5)->create(['event_id' => $popular->id, 'type' => ActivityType::EventClick]);
    UserActivityLog::factory()->count(20)->create(['event_id' => $popular->id, 'type' => ActivityType::EventImpression]);
    UserActivityLog::factory()->count(1)->create(['event_id' => $quiet->id, 'type' => ActivityType::EventClick]);

    $top = $this->reporter->summary()['top_events'];

    expect($top[0])->toMatchArray([
        'id' => $popular->id,
        'title' => 'Concert popular',
        'clicks' => 5,
        'impressions' => 20,
    ])->and($top[1]['id'])->toBe($quiet->id);
});

it('ranks search terms and ignores filter-only browses', function () {
    UserActivityLog::factory()->count(3)->create([
        'type' => ActivityType::Search,
        'context' => ['filters' => ['search' => 'Jazz']],
    ]);
    UserActivityLog::factory()->create([
        'type' => ActivityType::Search,
        'context' => ['filters' => ['search' => 'teatru']],
    ]);
    UserActivityLog::factory()->create([
        'type' => ActivityType::Search,
        'context' => ['filters' => ['category' => 'music']],
    ]);

    $searches = $this->reporter->summary()['top_searches'];

    // Case-folded, so "Jazz" and "jazz" are one term rather than two.
    expect($searches)->toBe([
        ['term' => 'jazz', 'hits' => 3],
        ['term' => 'teatru', 'hits' => 1],
    ]);
});

it('reports every activity type, including the ones with no hits', function () {
    $counts = $this->reporter->summary()['counts'];

    expect($counts)->toHaveCount(count(ActivityType::cases()))
        ->and($counts[ActivityType::EventClick->value])->toBe(0);
});

it('ignores activity outside the window', function () {
    UserActivityLog::factory()->count(4)->create([
        'type' => ActivityType::EventClick,
        'created_at' => now()->subDays(60),
    ]);

    expect($this->reporter->summary(30)['counts'][ActivityType::EventClick->value])->toBe(0)
        ->and($this->reporter->summary(90)['counts'][ActivityType::EventClick->value])->toBe(4);
});
