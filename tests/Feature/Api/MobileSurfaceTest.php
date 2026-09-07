<?php

declare(strict_types=1);

use App\Enums\ActivitySurface;
use App\Enums\ActivityType;
use App\Models\Event;
use App\Models\User;
use App\Models\UserActivityLog;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create(), ['*']);
    $this->event = Event::factory()->create(['starts_at' => now()->addDay()]);
});

it('records an explicit from surface on the browse impressions', function () {
    $this->getJson('/api/v1/events?from=mobile_browse')->assertOk();

    expect(UserActivityLog::ofType(ActivityType::EventImpression)->sole()->surface)->toBe(ActivitySurface::MobileBrowse);
});

it('falls back to the screen default when the client identifies itself as the app', function () {
    $this->withHeader('X-Ghes-Client', 'mobile')->getJson('/api/v1/events')->assertOk();
    $this->withHeader('X-Ghes-Client', 'mobile')->getJson("/api/v1/events/{$this->event->id}")->assertOk();
    $this->withHeader('X-Ghes-Client', 'mobile')->getJson('/api/v1/recommendations')->assertOk();
    $this->withHeader('X-Ghes-Client', 'mobile')->postJson("/api/v1/events/{$this->event->id}/click")->assertOk();

    $surfaces = UserActivityLog::query()->orderBy('created_at')->get()
        ->groupBy(fn (UserActivityLog $log) => $log->type->value)
        ->map(fn ($logs) => $logs->pluck('surface')->map(fn (ActivitySurface $s) => $s->value)->unique()->values()->all())
        ->all();

    expect($surfaces[ActivityType::EventImpression->value])->toContain('mobile_browse')
        ->and($surfaces[ActivityType::EventView->value])->toBe(['mobile_event_detail'])
        ->and($surfaces[ActivityType::EventClick->value])->toBe(['mobile_event_detail']);

    expect(UserActivityLog::where('surface', ActivitySurface::Api->value)->count())->toBe(0);
});

it('records the saved surface for the saved list when the client is the app', function () {
    auth()->user()->bookmarks()->create(['event_id' => $this->event->id]);

    $this->withHeader('X-Ghes-Client', 'mobile')->getJson('/api/v1/events/saved')->assertOk();

    expect(UserActivityLog::ofType(ActivityType::EventImpression)->sole()->surface)->toBe(ActivitySurface::MobileSaved);
});

it('records the feed surface for the recommendations when the client is the app', function () {
    $this->withHeader('X-Ghes-Client', 'mobile')->getJson('/api/v1/recommendations')->assertOk();

    // The factory event may or may not be recommended; what matters is that
    // nothing landed on `api`.
    expect(UserActivityLog::where('surface', ActivitySurface::Api->value)->count())->toBe(0)
        ->and(UserActivityLog::whereIn('surface', ['mobile_feed'])->count())
        ->toBe(UserActivityLog::ofType(ActivityType::EventImpression)->count());
});

it('lets from=push win over the app default, as the web digest links do', function () {
    $this->withHeader('X-Ghes-Client', 'mobile')->getJson("/api/v1/events/{$this->event->id}?from=push")->assertOk();

    expect(UserActivityLog::ofType(ActivityType::EventView)->sole()->surface)->toBe(ActivitySurface::Push);
});

it('keeps the ambiguous api surface for a caller that says nothing', function () {
    $this->getJson('/api/v1/events')->assertOk();
    $this->getJson("/api/v1/events/{$this->event->id}")->assertOk();

    expect(UserActivityLog::where('surface', ActivitySurface::Api->value)->count())
        ->toBe(UserActivityLog::count());
});

it('does not flag an authenticated app request without a user agent as a bot', function () {
    $this->withHeaders(['X-Ghes-Client' => 'mobile', 'User-Agent' => ''])
        ->getJson('/api/v1/events')->assertOk();

    expect(UserActivityLog::ofType(ActivityType::EventImpression)->sole()->is_bot)->toBeFalse();
});

it('discards activity from a client whose user agent looks like a scanner', function () {
    $this->withHeaders(['X-Ghes-Client' => 'mobile', 'User-Agent' => 'GhesPreviewFetcher/1.0'])
        ->getJson('/api/v1/events')->assertOk();

    expect(UserActivityLog::ofType(ActivityType::EventImpression)->sole()->is_bot)->toBeTrue();
});
