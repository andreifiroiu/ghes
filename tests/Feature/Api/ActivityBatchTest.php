<?php

declare(strict_types=1);

use App\Enums\ActivitySurface;
use App\Enums\ActivityType;
use App\Jobs\ProcessActivitySignalJob;
use App\Models\Event;
use App\Models\User;
use App\Models\UserActivityLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user, ['*']);
    $this->event = Event::factory()->create(['starts_at' => now()->addDays(5)]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function activityBatchItem(array $overrides = []): array
{
    return [
        'id' => (string) Str::uuid(),
        'type' => 'event_impression',
        'event_id' => test()->event->id,
        'from' => 'mobile_browse',
        'at' => now()->subMinutes(5)->toIso8601String(),
        ...$overrides,
    ];
}

/**
 * @param  list<array<string, mixed>>  $events
 * @param  array<string, string>  $headers
 */
function postActivityBatch(array $events, array $headers = ['X-Ghes-Client' => 'mobile']): TestResponse
{
    // Default headers persist across requests within a test, so a later
    // call without the app header must not inherit it from an earlier one.
    return test()->flushHeaders()->withHeaders($headers)->postJson('/api/v1/activity', ['events' => $events]);
}

it('stores each item on the surface it names, keeping the moment it happened', function () {
    $at = now()->subHours(3)->startOfSecond();
    $item = activityBatchItem(['at' => $at->toIso8601String()]);

    postActivityBatch([$item])
        ->assertStatus(202)
        ->assertJsonPath('data.accepted', 1)
        ->assertJsonPath('data.duplicates', 0)
        ->assertJsonPath('data.dropped', 0);

    $log = UserActivityLog::sole();

    expect($log->type)->toBe(ActivityType::EventImpression)
        ->and($log->surface)->toBe(ActivitySurface::MobileBrowse)
        ->and($log->user_id)->toBe($this->user->id)
        ->and($log->event_id)->toBe($this->event->id)
        ->and($log->client_event_id)->toBe($item['id'])
        ->and($log->is_bot)->toBeFalse()
        ->and($log->created_at->timestamp)->toBe($at->timestamp)
        ->and($log->context)->toBe(['reported_by' => 'client']);
});

it('accepts the timestamp shapes a device emits and rejects one without an offset', function () {
    postActivityBatch([
        activityBatchItem(['at' => '2026-09-07T12:00:00.000Z']),
        activityBatchItem(['at' => '2026-09-07T14:00:00+02:00']),
    ])->assertStatus(202)->assertJsonPath('data.accepted', 2);

    postActivityBatch([activityBatchItem(['at' => '2026-09-07 14:00:00'])])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['events.0.at']]]);
});

it('is idempotent on the client id: a replayed batch writes nothing new', function () {
    $batch = [activityBatchItem(), activityBatchItem(['from' => 'mobile_feed'])];

    postActivityBatch($batch)->assertStatus(202)->assertJsonPath('data.accepted', 2);
    postActivityBatch($batch)->assertStatus(202)
        ->assertJsonPath('data.accepted', 0)
        ->assertJsonPath('data.duplicates', 2)
        ->assertJsonPath('data.dropped', 0);

    expect(UserActivityLog::count())->toBe(2);
});

it('scopes the client id to the account: two users may report the same id', function () {
    $item = activityBatchItem();
    postActivityBatch([$item])->assertStatus(202)->assertJsonPath('data.accepted', 1);

    Sanctum::actingAs(User::factory()->create(), ['*']);
    postActivityBatch([$item])->assertStatus(202)->assertJsonPath('data.accepted', 1);

    expect(UserActivityLog::where('client_event_id', $item['id'])->count())->toBe(2);
});

it('keeps the first of two items that share an id within one batch', function () {
    $id = (string) Str::uuid();

    postActivityBatch([
        activityBatchItem(['id' => $id, 'from' => 'mobile_feed']),
        activityBatchItem(['id' => $id, 'from' => 'mobile_saved']),
    ])->assertStatus(202)->assertJsonPath('data.accepted', 1)->assertJsonPath('data.duplicates', 1);

    expect(UserActivityLog::sole()->surface)->toBe(ActivitySurface::MobileFeed);
});

it('drops an item about an event that no longer exists, counts it, and says so in the log', function () {
    Log::spy();
    $gone = (string) Str::uuid();

    postActivityBatch([activityBatchItem(), activityBatchItem(['event_id' => $gone])])
        ->assertStatus(202)
        ->assertJsonPath('data.accepted', 1)
        ->assertJsonPath('data.duplicates', 0)
        ->assertJsonPath('data.dropped', 1);

    expect(UserActivityLog::count())->toBe(1);

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => str_contains($message, 'dropped')
            && $context['event_ids'] === [$gone]
            && $context['user_id'] === $this->user->id,
    );
});

it('files an impression of a merged event under its canonical twin, as the click does', function () {
    $canonical = Event::factory()->create(['starts_at' => now()->addDays(5)]);
    $this->event->update(['merged_into_id' => $canonical->id]);

    postActivityBatch([activityBatchItem()])->assertStatus(202)->assertJsonPath('data.accepted', 1);

    expect(UserActivityLog::sole()->event_id)->toBe($canonical->id);
});

it('drops an impression of a hidden event, which the web would 404', function () {
    $this->event->update(['is_hidden' => true]);

    postActivityBatch([activityBatchItem()])->assertStatus(202)->assertJsonPath('data.dropped', 1);

    expect(UserActivityLog::count())->toBe(0);
});

it('validates every item by its own index, not by the first', function () {
    postActivityBatch([activityBatchItem(), activityBatchItem(['event_id' => null])])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['events.1.event_id']]])
        ->assertJsonMissingPath('error.details.events.0.event_id');

    expect(UserActivityLog::count())->toBe(0);
});

it('accepts impressions only: the endpoints that serve views, clicks and searches already log them', function () {
    foreach (['event_view', 'event_click', 'search', 'bookmark_added'] as $type) {
        postActivityBatch([activityBatchItem(['type' => $type])])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['events.0.type']]]);
    }

    expect(UserActivityLog::count())->toBe(0);
});

it('lets the app file rows only under its own surfaces, push and api', function () {
    postActivityBatch([activityBatchItem(['from' => 'push'])])->assertStatus(202);

    foreach (['digest', 'dashboard', 'events_index', 'admin'] as $surface) {
        postActivityBatch([activityBatchItem(['from' => $surface])])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['events.0.from']]]);
    }

    expect(UserActivityLog::sole()->surface)->toBe(ActivitySurface::Push);
});

it('caps a batch at one hundred items', function () {
    $batch = array_map(fn () => activityBatchItem(), range(1, 101));

    postActivityBatch($batch)->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['events']]]);
});

it('clamps a stale timestamp to seven days back and a future one to now', function () {
    $this->travelTo(now()->startOfSecond());

    postActivityBatch([
        activityBatchItem(['at' => now()->subDays(30)->toIso8601String()]),
        activityBatchItem(['at' => now()->addDay()->toIso8601String()]),
    ])->assertStatus(202)->assertJsonPath('data.accepted', 2);

    $timestamps = UserActivityLog::query()->orderBy('created_at')->pluck('created_at')
        ->map(fn ($at) => $at->timestamp)->all();

    expect($timestamps)->toBe([now()->subDays(7)->timestamp, now()->timestamp]);
});

it('defaults the surface to the browse screen for the identified app and to api for anyone else', function () {
    postActivityBatch([activityBatchItem(['from' => null])])->assertStatus(202);
    postActivityBatch([activityBatchItem(['from' => null])], [])->assertStatus(202);

    $surfaces = UserActivityLog::query()->get()
        ->map(fn (UserActivityLog $log) => $log->surface->value)->sort()->values()->all();

    expect($surfaces)->toBe(['api', 'mobile_browse']);
});

it('flags a batch sent by a denylisted user agent as bot traffic', function () {
    postActivityBatch([activityBatchItem()], ['X-Ghes-Client' => 'mobile', 'User-Agent' => 'curl/8.4.0'])
        ->assertStatus(202);

    $log = UserActivityLog::sole();

    expect($log->is_bot)->toBeTrue()
        ->and($log->context['bot_reason'])->toBe('ua_denylist');
});

it('never dispatches a profile signal for a reported impression', function () {
    Queue::fake();

    postActivityBatch([activityBatchItem()])->assertStatus(202)->assertJsonPath('data.accepted', 1);

    Queue::assertNotPushed(ProcessActivitySignalJob::class);
});

it('lets the app opt out of server-side impressions once it has reported some itself', function () {
    $this->user->bookmarks()->create(['event_id' => $this->event->id]);
    postActivityBatch([activityBatchItem()])->assertStatus(202);
    $headers = ['X-Ghes-Client' => 'mobile', 'X-Ghes-Impressions' => 'client'];

    $this->withHeaders($headers)->getJson('/api/v1/events?category=music')->assertOk();
    $this->withHeaders($headers)->getJson('/api/v1/events/saved')->assertOk();
    $this->withHeaders($headers)->getJson('/api/v1/recommendations')->assertOk();

    // The one client-reported impression is the only one; the browse still
    // records its search row — only the impressions moved to the client.
    expect(UserActivityLog::ofType(ActivityType::EventImpression)->count())->toBe(1)
        ->and(UserActivityLog::ofType(ActivityType::EventImpression)->whereNull('client_event_id')->count())->toBe(0)
        ->and(UserActivityLog::ofType(ActivityType::Search)->count())->toBe(1);
});

it('keeps counting server-side until the app has actually reported a client impression', function () {
    $headers = ['X-Ghes-Client' => 'mobile', 'X-Ghes-Impressions' => 'client'];

    $this->withHeaders($headers)->getJson('/api/v1/events')->assertOk();

    expect(UserActivityLog::ofType(ActivityType::EventImpression)->whereNull('client_event_id')->count())->toBe(1);
});

it('resumes counting server-side when the client stops reporting for longer than the trust window', function () {
    $headers = ['X-Ghes-Client' => 'mobile', 'X-Ghes-Impressions' => 'client'];
    postActivityBatch([activityBatchItem()])->assertStatus(202);

    $this->travel(25)->hours();
    $this->withHeaders($headers)->getJson('/api/v1/events')->assertOk();

    expect(UserActivityLog::ofType(ActivityType::EventImpression)->whereNull('client_event_id')->count())->toBe(1);
});

it('ignores the impressions header from a caller that is not the app', function () {
    postActivityBatch([activityBatchItem()])->assertStatus(202);

    $this->flushHeaders()->withHeaders(['X-Ghes-Impressions' => 'client'])->getJson('/api/v1/events')->assertOk();

    expect(UserActivityLog::ofType(ActivityType::EventImpression)->whereNull('client_event_id')->count())->toBe(1);
});

it('is throttled per user', function () {
    config(['eventpulse.api.throttle.activity_per_minute' => 2]);

    postActivityBatch([activityBatchItem()])->assertStatus(202);
    postActivityBatch([activityBatchItem()])->assertStatus(202);
    postActivityBatch([activityBatchItem()])->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
});
