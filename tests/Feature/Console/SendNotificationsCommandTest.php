<?php

declare(strict_types=1);

use App\Enums\NotificationChannel;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notification\NotificationComposer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

function pendingNotificationFor(User $user): Notification
{
    return Notification::factory()->create([
        'user_id' => $user->id,
        'sent_at' => null,
        'body_html' => null,
        'event_ids' => [],
        'discovery_event_ids' => [],
    ]);
}

/**
 * Swap the composer out: what changed is how composed digests are delivered,
 * not how they are built, and the real composer drags in the whole
 * recommendation engine.
 *
 * @param  Collection<int, Notification>  $notifications
 */
function fakeComposerReturning(Collection $notifications, ?Notification $forOneUser = null): void
{
    $composer = Mockery::mock(NotificationComposer::class);
    $composer->shouldReceive('composeForAll')->andReturn($notifications);
    $composer->shouldReceive('compose')->andReturn($forOneUser);

    app()->instance(NotificationComposer::class, $composer);
}

it('queues one job per digest rather than sending inline', function () {
    Queue::fake();
    Mail::fake();

    $users = User::factory()->count(2)->create(['notification_channel' => NotificationChannel::Email]);
    $notifications = $users->map(fn (User $user) => pendingNotificationFor($user));

    fakeComposerReturning($notifications);

    $this->artisan('eventpulse:send-notifications')
        ->expectsOutputToContain('Queued 2 notifications')
        ->assertSuccessful();

    Queue::assertPushed(SendNotificationJob::class, 2);

    // Nothing was delivered inline: the worker marks sent_at, not the command.
    expect($notifications->every(fn (Notification $n): bool => $n->fresh()->sent_at === null))->toBeTrue();
});

/**
 * Delivery is an outbound API call per recipient, so it belongs on the queue
 * that already carries tries = 3 and a backoff — not inline in the scheduled run.
 */
it('puts the jobs on the notifications queue carrying the notification id', function () {
    Queue::fake();

    $user = User::factory()->create(['notification_channel' => NotificationChannel::Email]);
    $notification = pendingNotificationFor($user);

    fakeComposerReturning(collect([$notification]));

    $this->artisan('eventpulse:send-notifications')->assertSuccessful();

    Queue::assertPushedOn('notifications', SendNotificationJob::class,
        fn (SendNotificationJob $job): bool => $job->notificationId === $notification->id);
});

it('sends inline under --sync so delivery can be verified without a worker', function () {
    Queue::fake();
    Mail::fake();

    $user = User::factory()->create(['notification_channel' => NotificationChannel::Email]);
    $notification = pendingNotificationFor($user);
    fakeComposerReturning(collect([$notification]));

    $this->artisan('eventpulse:send-notifications', ['--sync' => true])
        ->expectsOutputToContain('Successfully sent 1/1 notifications.')
        ->assertSuccessful();

    expect($notification->fresh()->sent_at)->not->toBeNull();
    Queue::assertNothingPushed();
});

it('queues the digest for a single user', function () {
    Queue::fake();

    $user = User::factory()->create(['notification_channel' => NotificationChannel::Email]);
    $notification = pendingNotificationFor($user);

    fakeComposerReturning(collect(), $notification);

    $this->artisan('eventpulse:send-notifications', ['--user' => $user->id])
        ->expectsOutputToContain('Queued 1 notifications')
        ->assertSuccessful();

    Queue::assertPushed(SendNotificationJob::class, 1);
});

it('reports when a single user has nothing to recommend', function () {
    Queue::fake();

    $user = User::factory()->create();
    fakeComposerReturning(collect(), null);

    $this->artisan('eventpulse:send-notifications', ['--user' => $user->id])
        ->expectsOutputToContain('No events to recommend for this user.')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

it('reports when nobody is due', function () {
    Queue::fake();

    fakeComposerReturning(collect());

    $this->artisan('eventpulse:send-notifications')
        ->expectsOutputToContain('No users are due for notifications.')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});
