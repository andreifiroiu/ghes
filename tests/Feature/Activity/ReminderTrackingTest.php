<?php

declare(strict_types=1);

use App\Enums\ActivitySurface;
use App\Enums\ActivityType;
use App\Enums\NotificationChannel;
use App\Models\Event;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\Activity\ActivityReporter;
use App\Services\Notification\ReminderEmailRenderer;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    $this->withoutVite();
});

function trackedReminder(User $user): Notification
{
    $event = Event::withoutSyncingToSearch(
        fn (): Event => Event::factory()->startingIn(175)->create(),
    );

    return Notification::factory()->reminder($event, 180)->create([
        'user_id' => $user->id,
        'channel' => NotificationChannel::Email,
        'sent_at' => now(),
    ]);
}

it('records an open from the reminder pixel', function () {
    $user = User::factory()->create();
    $reminder = trackedReminder($user);

    $this->get(URL::signedRoute('notifications.open', ['notification' => $reminder->id]), browserHeaders())
        ->assertOk();

    expect($reminder->fresh()->opened_at)->not->toBeNull();
});

it('attributes a click from a reminder to the reminder surface', function () {
    $user = User::factory()->create();
    $reminder = trackedReminder($user);
    $eventId = $reminder->event_id;

    $this->actingAs($user)
        ->get(route('events.show', ['event' => $eventId, 'from' => 'reminder', 'n' => $reminder->id]), browserHeaders())
        ->assertOk();

    $log = UserActivityLog::query()
        ->where('notification_id', $reminder->id)
        ->where('type', ActivityType::EventView)
        ->firstOrFail();

    expect($log->surface)->toBe(ActivitySurface::Reminder);
});

/**
 * The regression guard for the coupling that made extending this table risky:
 * every consumer of `event_notifications` predates reminders and means digests.
 * A reminder is a single-event mail to someone who already saved the event, so
 * its open rate is far higher — counted here, it would inflate the admin
 * digest open rate until the number no longer meant what its label says.
 */
it('keeps sent reminders out of the digest open rate and click count', function () {
    $user = User::factory()->create();

    Notification::factory()->create([
        'user_id' => $user->id,
        'channel' => NotificationChannel::Email,
        'sent_at' => now(),
        'opened_at' => null,
    ]);

    $before = app(ActivityReporter::class)->summary()['digest'];

    $reminder = trackedReminder($user);
    $this->get(URL::signedRoute('notifications.open', ['notification' => $reminder->id]), browserHeaders())
        ->assertOk();

    $this->actingAs($user)
        ->get(route('events.show', ['event' => $reminder->event_id, 'from' => 'reminder', 'n' => $reminder->id]), browserHeaders())
        ->assertOk();

    $after = app(ActivityReporter::class)->summary()['digest'];

    expect($after)->toBe($before);
});

it('unsubscribes from reminders through a signed link without touching the digest', function () {
    $user = User::factory()->create([
        'notification_channel' => NotificationChannel::Email,
        'notification_frequency' => 'daily',
        'event_reminders_enabled' => true,
    ]);
    $reminder = trackedReminder($user);

    $url = ReminderEmailRenderer::unsubscribeUrl($reminder);

    // The GET only renders a confirmation: mail clients and corporate link
    // scanners fetch every URL in a message, and a mutating GET would
    // unsubscribe people who never clicked.
    $this->get($url)->assertOk();
    expect($user->fresh()->event_reminders_enabled)->toBeTrue();

    $this->post($url)->assertRedirect();

    $user->refresh();
    expect($user->event_reminders_enabled)->toBeFalse()
        // The digest is what someone signed up for; one unwanted reminder must
        // not silently cancel it.
        ->and($user->notification_channel)->toBe(NotificationChannel::Email);
});

it('rejects an unsigned unsubscribe link', function () {
    $user = User::factory()->create(['event_reminders_enabled' => true]);

    $this->post(route('unsubscribe.reminders.confirm', ['user' => $user->id]))
        ->assertForbidden();

    expect($user->fresh()->event_reminders_enabled)->toBeTrue();
});
