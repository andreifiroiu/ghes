<?php

declare(strict_types=1);

use App\Jobs\SendNotificationJob;
use App\Models\Event;
use App\Models\EventBookmark;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notification\ReminderComposer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/**
 * Swap the composer out where the test is about delivery rather than about who
 * is owed a reminder — ReminderComposerTest already covers the selection.
 *
 * @param  Collection<int, Notification>  $notifications
 */
function fakeReminderComposerReturning(Collection $notifications): void
{
    $composer = Mockery::mock(ReminderComposer::class);
    $composer->shouldReceive('composeDue')->andReturn($notifications);

    app()->instance(ReminderComposer::class, $composer);
}

function pendingReminderRow(User $user): Notification
{
    $event = Event::withoutSyncingToSearch(
        fn (): Event => Event::factory()->startingIn(175)->create(),
    );

    return Notification::factory()->reminder($event, 180)->create([
        'user_id' => $user->id,
        'sent_at' => null,
        'body_html' => null,
    ]);
}

it('queues one job per reminder rather than sending inline', function () {
    Queue::fake();
    Mail::fake();

    $users = User::factory()->count(2)->create(['notification_channel' => 'email']);
    $reminders = $users->map(fn (User $user) => pendingReminderRow($user));

    fakeReminderComposerReturning($reminders);

    $this->artisan('eventpulse:send-reminders')
        ->expectsOutputToContain('Queued 2 reminders')
        ->assertSuccessful();

    Queue::assertPushed(SendNotificationJob::class, 2);

    expect($reminders->every(fn (Notification $n): bool => $n->fresh()->sent_at === null))->toBeTrue();
});

it('puts the jobs on the notifications queue carrying the reminder id', function () {
    Queue::fake();

    $user = User::factory()->create(['notification_channel' => 'email']);
    $reminder = pendingReminderRow($user);

    fakeReminderComposerReturning(collect([$reminder]));

    $this->artisan('eventpulse:send-reminders')->assertSuccessful();

    Queue::assertPushedOn('notifications', SendNotificationJob::class,
        fn (SendNotificationJob $job): bool => $job->notificationId === $reminder->id);
});

it('sends inline under --sync so delivery can be verified without a worker', function () {
    Queue::fake();
    Mail::fake();

    $user = User::factory()->create(['notification_channel' => 'email']);
    $reminder = pendingReminderRow($user);

    fakeReminderComposerReturning(collect([$reminder]));

    $this->artisan('eventpulse:send-reminders', ['--sync' => true])
        ->expectsOutputToContain('Successfully sent 1/1 reminders.')
        ->assertSuccessful();

    expect($reminder->fresh()->sent_at)->not->toBeNull();
    Queue::assertNothingPushed();
});

it('reports when nothing is due', function () {
    Queue::fake();

    fakeReminderComposerReturning(collect());

    $this->artisan('eventpulse:send-reminders')
        ->expectsOutputToContain('No reminders are due.')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

it('composes end to end for a single user, without a faked composer', function () {
    Queue::fake();

    config([
        'eventpulse.reminders.enabled' => true,
        'eventpulse.reminders.lead_options' => [180],
        'eventpulse.reminders.default_lead_minutes' => [180],
    ]);

    $user = User::factory()->create([
        'notification_channel' => 'email',
        'email_verified_at' => now(),
    ]);
    $other = User::factory()->create([
        'notification_channel' => 'email',
        'email_verified_at' => now(),
    ]);

    $event = Event::withoutSyncingToSearch(
        fn (): Event => Event::factory()->startingIn(175)->create(),
    );

    EventBookmark::factory()->create(['user_id' => $user->id, 'event_id' => $event->id]);
    EventBookmark::factory()->create(['user_id' => $other->id, 'event_id' => $event->id]);

    $this->artisan('eventpulse:send-reminders', ['--user' => $user->id])
        ->expectsOutputToContain('Queued 1 reminders')
        ->assertSuccessful();

    Queue::assertPushed(SendNotificationJob::class, 1);

    expect(Notification::query()->reminders()->pluck('user_id')->all())->toBe([$user->id]);
});
