<?php

declare(strict_types=1);

use App\Enums\ActivitySurface;
use App\Enums\ActivityType;
use App\Enums\EventCategory;
use App\Enums\Reaction;
use App\Jobs\ProcessActivitySignalJob;
use App\Models\DiscoveryLog;
use App\Models\Event;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\Feedback\ReactionRecorder;
use App\Services\Recommendation\FeedbackProcessor;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->event = Event::factory()->create([
        'category' => EventCategory::Music,
        'tags' => ['jazz'],
        'starts_at' => now()->addWeek(),
    ]);
});

it('records a calendar download and still returns the file', function () {
    $response = $this->withHeaders(browserHeaders())
        ->get("/events/{$this->event->id}/calendar.ics");

    $response->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8');

    $log = UserActivityLog::ofType(ActivityType::CalendarDownload)->sole();

    expect($log->event_id)->toBe($this->event->id)
        ->and($log->surface)->toBe(ActivitySurface::EventDetail)
        ->and($log->is_bot)->toBeFalse();
});

it('does not record a download the guards rejected', function () {
    $undated = Event::factory()->create(['starts_at' => null]);
    $hidden = Event::factory()->create(['is_hidden' => true, 'starts_at' => now()->addWeek()]);

    // A 404 is not a statement of interest.
    $this->withHeaders(browserHeaders())->get("/events/{$undated->id}/calendar.ics")->assertNotFound();
    $this->withHeaders(browserHeaders())->get("/events/{$hidden->id}/calendar.ics")->assertNotFound();

    expect(UserActivityLog::count())->toBe(0);
});

it('attributes a download arriving from the digest', function () {
    $user = User::factory()->create();
    $notification = Notification::factory()->for($user)->create();

    $this->withHeaders(browserHeaders())
        ->get("/events/{$this->event->id}/calendar.ics?from=digest&n={$notification->id}")
        ->assertOk();

    $log = UserActivityLog::ofType(ActivityType::CalendarDownload)->sole();

    expect($log->surface)->toBe(ActivitySurface::Digest)
        ->and($log->notification_id)->toBe($notification->id);
});

it('nudges the profile only for an authenticated download', function () {
    Queue::fake();

    $this->withHeaders(browserHeaders())->get("/events/{$this->event->id}/calendar.ics");
    Queue::assertNotPushed(ProcessActivitySignalJob::class);

    $this->actingAs(User::factory()->create())
        ->withHeaders(browserHeaders())
        ->get("/events/{$this->event->id}/calendar.ics");

    Queue::assertPushed(ProcessActivitySignalJob::class, 1);
});

it('moves the profile more than a click does', function () {
    $user = User::factory()->create(['interest_profile' => []]);

    $log = UserActivityLog::factory()->create([
        'user_id' => $user->id,
        'event_id' => $this->event->id,
        'type' => ActivityType::CalendarDownload,
    ]);

    app(FeedbackProcessor::class)->processImplicitSignal($log);

    // Committing a slot in your week says more than following a link, but
    // still less than saying "mă interesează" out loud.
    expect($user->fresh()->interest_profile['music'])->toBe(0.10)
        ->and($user->fresh()->interest_profile['tag:jazz'])->toBe(0.12);
});

it('scores a click and a calendar download separately', function () {
    $user = User::factory()->create(['interest_profile' => []]);

    foreach ([ActivityType::EventClick, ActivityType::CalendarDownload] as $type) {
        app(FeedbackProcessor::class)->processImplicitSignal(
            UserActivityLog::factory()->create([
                'user_id' => $user->id,
                'event_id' => $this->event->id,
                'type' => $type,
            ]),
        );
    }

    // Two different statements about the same event; one must not mask the other.
    expect($user->fresh()->interest_profile['music'])->toEqualWithDelta(0.15, 0.0001);
});

it('scores each type only once per event', function () {
    $user = User::factory()->create(['interest_profile' => []]);

    foreach (range(1, 3) as $ignored) {
        app(FeedbackProcessor::class)->processImplicitSignal(
            UserActivityLog::factory()->create([
                'user_id' => $user->id,
                'event_id' => $this->event->id,
                'type' => ActivityType::CalendarDownload,
            ]),
        );
    }

    expect($user->fresh()->interest_profile['music'])->toBe(0.10);
});

it('resolves a discovery as a calendar download, outranking a click', function () {
    $user = User::factory()->create(['interest_profile' => []]);
    $discovery = DiscoveryLog::factory()->create([
        'user_id' => $user->id,
        'event_id' => $this->event->id,
        'outcome' => null,
    ]);

    app(FeedbackProcessor::class)->processImplicitSignal(
        UserActivityLog::factory()->create([
            'user_id' => $user->id,
            'event_id' => $this->event->id,
            'type' => ActivityType::EventClick,
        ]),
    );
    expect($discovery->fresh()->outcome)->toBe('clicked');

    app(FeedbackProcessor::class)->processImplicitSignal(
        UserActivityLog::factory()->create([
            'user_id' => $user->id,
            'event_id' => $this->event->id,
            'type' => ActivityType::CalendarDownload,
        ]),
    );

    // Both are implicit, so they rank by commitment rather than by recency.
    expect($discovery->fresh()->outcome)->toBe('calendar');
});

it('still lets an explicit reaction override a calendar download', function () {
    $user = User::factory()->create(['interest_profile' => []]);
    $discovery = DiscoveryLog::factory()->create([
        'user_id' => $user->id,
        'event_id' => $this->event->id,
        'outcome' => null,
    ]);

    app(FeedbackProcessor::class)->processImplicitSignal(
        UserActivityLog::factory()->create([
            'user_id' => $user->id,
            'event_id' => $this->event->id,
            'type' => ActivityType::CalendarDownload,
        ]),
    );

    // Downloading the file and then saying "not for me" means the exploration
    // missed, whatever the download implied.
    app(ReactionRecorder::class)->record($user, $this->event->id, Reaction::NotInterested);
    app(FeedbackProcessor::class)->processUnprocessed();

    expect($discovery->fresh()->outcome)->toBe('not_interested');
});

it('weighs a calendar download into the engagement score', function () {
    config(['eventpulse.activity.engagement_ceiling' => 10]);

    UserActivityLog::factory()->count(2)->create([
        'event_id' => $this->event->id,
        'type' => ActivityType::CalendarDownload,
    ]);

    $this->artisan('eventpulse:aggregate-engagement')->assertSuccessful();

    // 2 × 5.0 against a ceiling of 10.
    expect($this->event->fresh()->engagement_score)->toBe(100);
});
