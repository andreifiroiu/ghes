<?php

declare(strict_types=1);

use App\DTOs\PushFanoutResult;
use App\Enums\ActivitySurface;
use App\Enums\ActivityType;
use App\Enums\NotificationChannel;
use App\Enums\PushPayloadType;
use App\Models\Event;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\Activity\ActivityLogger;
use App\Services\Notification\NotificationDispatcher;
use App\Services\Notification\Presenters\NotificationPresenters;
use App\Services\Notification\PushFanout;
use App\Services\Notification\PushPayload;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    // Outside the quiet window, so a test about push is about push.
    config(['eventpulse.reminders.quiet_hours' => ['from' => 3, 'to' => 4]]);
});

function reminderDispatcher(PushFanout $pushFanout): NotificationDispatcher
{
    return new NotificationDispatcher(
        app(NotificationPresenters::class),
        $pushFanout,
        app(ActivityLogger::class),
    );
}

function pendingReminder(User $user, Event $event, int $leadMinutes = 180): Notification
{
    return Notification::factory()->reminder($event, $leadMinutes)->create([
        'user_id' => $user->id,
        'channel' => $user->notification_channel,
        'subject' => null,
        'body_html' => null,
        'sent_at' => null,
    ]);
}

function reminderEvent(int $startsInMinutes = 175, array $attributes = []): Event
{
    return Event::withoutSyncingToSearch(
        fn (): Event => Event::factory()->startingIn($startsInMinutes)->create($attributes),
    );
}

function silentPushFanout(): PushFanout
{
    $push = Mockery::mock(PushFanout::class);
    $push->shouldReceive('sendToUser')->never();

    return $push;
}

it('renders the reminder template, not the digest', function () {
    Mail::fake();
    $user = User::factory()->create(['notification_channel' => NotificationChannel::Email]);
    $event = reminderEvent(175, ['title' => 'Concert la Capitol']);
    $notification = pendingReminder($user, $event);

    reminderDispatcher(silentPushFanout())->dispatch($notification);

    $notification->refresh();

    expect($notification->sent_at)->not->toBeNull()
        ->and($notification->body_html)->toContain('Concert la Capitol')
        // The reminder is about one event and says so; it must not carry the
        // digest's recommendation sections.
        ->and($notification->body_html)->not->toContain('Recomandate pentru tine')
        ->and($notification->body_html)->toContain('Peste 3 ore')
        // Bulk mail needs an opt-out a reader can reach without a session.
        ->and($notification->body_html)->toContain('/unsubscribe/reminders/');
});

it('writes the resolved subject onto the row so history is not blank', function () {
    Mail::fake();
    $user = User::factory()->create(['notification_channel' => NotificationChannel::Email]);
    $event = reminderEvent(175, ['title' => 'Seară de jazz']);
    $notification = pendingReminder($user, $event);

    reminderDispatcher(silentPushFanout())->dispatch($notification);

    expect($notification->fresh()->subject)->toBe('Peste 3 ore: Seară de jazz');
});

it('offers a calendar link on the day-before tier but not the three-hour one', function () {
    Mail::fake();
    $user = User::factory()->create(['notification_channel' => NotificationChannel::Email]);

    $soonEvent = reminderEvent(175);
    $soon = pendingReminder($user, $soonEvent, 180);
    reminderDispatcher(silentPushFanout())->dispatch($soon);

    $tomorrowEvent = reminderEvent(1435);
    $tomorrow = pendingReminder($user, $tomorrowEvent, 1440);
    reminderDispatcher(silentPushFanout())->dispatch($tomorrow);

    // "Adaugă în calendar" three hours out is a dead affordance, and a link
    // scanner prefetching it would inject the heaviest engagement signal the
    // product records at reminder volume.
    expect($soon->fresh()->body_html)->not->toContain('calendar.ics')
        ->and($tomorrow->fresh()->body_html)->toContain('calendar.ics');
});

it('pushes a reminder payload carrying the event id and the reminders channel', function () {
    Mail::fake();
    $user = User::factory()->create(['notification_channel' => NotificationChannel::Push]);
    $event = reminderEvent();
    $notification = pendingReminder($user, $event);

    $captured = null;
    $push = Mockery::mock(PushFanout::class);
    $push->shouldReceive('sendToUser')
        ->once()
        ->andReturnUsing(function (User $u, PushPayload $payload) use (&$captured): PushFanoutResult {
            $captured = $payload;

            return new PushFanoutResult(web: 1, expo: 0, suppressed: 0);
        });

    reminderDispatcher($push)->dispatch($notification);

    expect($captured->type)->toBe(PushPayloadType::Reminder)
        ->and($captured->eventId)->toBe($event->id)
        ->and($captured->deepLink)->toBe('ghes://events/'.$event->id)
        // The shipped client registers `reminders`, not `reminder`; sending the
        // enum value would silently land this in the default channel.
        ->and($captured->type->androidChannel())->toBe('reminders');
});

it('attributes impressions to the reminder surface, not the digest', function () {
    Mail::fake();
    $user = User::factory()->create(['notification_channel' => NotificationChannel::Email]);
    $event = reminderEvent();
    $notification = pendingReminder($user, $event);

    reminderDispatcher(silentPushFanout())->dispatch($notification);

    $log = UserActivityLog::query()
        ->where('notification_id', $notification->id)
        ->where('type', ActivityType::EventImpression)
        ->firstOrFail();

    expect($log->surface)->toBe(ActivitySurface::Reminder)
        ->and($log->event_id)->toBe($event->id);
});

/**
 * Compose and send are decoupled, so a queue backlog is exactly the case where
 * the world moves underneath a composed row. sent_at stays null on purpose: the
 * row then reads as composed-but-never-delivered, and the unique index stops a
 * later run composing it again.
 */
it('does not deliver a reminder whose event has already started, and leaves sent_at null', function () {
    Mail::fake();
    $user = User::factory()->create(['notification_channel' => NotificationChannel::Email]);
    $event = reminderEvent(-30);
    $notification = pendingReminder($user, $event);

    reminderDispatcher(silentPushFanout())->dispatch($notification);

    expect($notification->fresh()->sent_at)->toBeNull();
    Mail::assertNothingSent();
});

it('does not deliver a reminder whose event has been moved out of its tier', function () {
    Mail::fake();
    $user = User::factory()->create(['notification_channel' => NotificationChannel::Email]);
    // Composed for the 3h tier; a scraper has since moved it three days out.
    $event = reminderEvent(3 * 24 * 60);
    $notification = pendingReminder($user, $event, 180);

    reminderDispatcher(silentPushFanout())->dispatch($notification);

    expect($notification->fresh()->sent_at)->toBeNull();
    Mail::assertNothingSent();
});

it('does not deliver a reminder whose event has been hidden', function () {
    Mail::fake();
    $user = User::factory()->create(['notification_channel' => NotificationChannel::Email]);
    $event = reminderEvent(175, ['is_hidden' => true]);
    $notification = pendingReminder($user, $event);

    reminderDispatcher(silentPushFanout())->dispatch($notification);

    expect($notification->fresh()->sent_at)->toBeNull();
});

/**
 * A reminder composed 20 minutes ago can name a duplicate that has since been
 * merged away. The mail must link at the survivor, not a row nobody will see.
 */
it('renders the canonical event when the one it names has been merged away', function () {
    Mail::fake();
    $user = User::factory()->create(['notification_channel' => NotificationChannel::Email]);

    [$canonical, $duplicate] = Event::withoutSyncingToSearch(function (): array {
        $canonical = Event::factory()->startingIn(175)->create(['title' => 'Titlul canonic']);
        $duplicate = Event::factory()->startingIn(175)->create(['title' => 'Titlul duplicat']);
        $duplicate->forceFill(['merged_into_id' => $canonical->id])->save();

        return [$canonical, $duplicate];
    });

    $notification = pendingReminder($user, $duplicate);

    reminderDispatcher(silentPushFanout())->dispatch($notification);

    expect($notification->fresh()->body_html)->toContain('Titlul canonic')
        ->and($notification->fresh()->body_html)->not->toContain('Titlul duplicat');
});

/**
 * An inbox can wait until morning; a lock screen cannot. The case this exists
 * for is a day-before reminder about a 01:00 after-party.
 */
it('suppresses the push inside quiet hours but still sends the email', function () {
    Mail::fake();
    config(['eventpulse.reminders.quiet_hours' => ['from' => 0, 'to' => 24]]);

    $user = User::factory()->create(['notification_channel' => NotificationChannel::Both]);
    $event = reminderEvent();
    $notification = pendingReminder($user, $event);

    reminderDispatcher(silentPushFanout())->dispatch($notification);

    expect($notification->fresh()->sent_at)->not->toBeNull()
        ->and($notification->fresh()->body_html)->not->toBeNull();
});

/**
 * The presenter refactor touched the only code path the digest has ever used.
 * This is the guard that it still behaves exactly as it did.
 */
it('still sends a digest with the digest template, subject and surface', function () {
    Mail::fake();
    $user = User::factory()->create(['notification_channel' => NotificationChannel::Email]);
    $event = reminderEvent();

    $notification = Notification::factory()->create([
        'user_id' => $user->id,
        'event_ids' => [$event->id],
        'discovery_event_ids' => [],
        'subject' => null,
        'body_html' => null,
        'sent_at' => null,
    ]);

    reminderDispatcher(silentPushFanout())->dispatch($notification);

    $notification->refresh();

    expect($notification->sent_at)->not->toBeNull()
        ->and($notification->subject)->toBe('Digestul tău Ghes')
        ->and($notification->body_html)->toContain('Recomandate pentru tine')
        // The digest has no separate opt-out state to point a link at yet.
        ->and($notification->body_html)->not->toContain('/unsubscribe/reminders/');

    $log = UserActivityLog::query()
        ->where('notification_id', $notification->id)
        ->where('type', ActivityType::EventImpression)
        ->firstOrFail();

    expect($log->surface)->toBe(ActivitySurface::Digest);
});
