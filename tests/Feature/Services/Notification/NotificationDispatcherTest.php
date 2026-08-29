<?php

declare(strict_types=1);

use App\Enums\NotificationChannel;
use App\Models\Notification;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Services\Notification\EmailRenderer;
use App\Services\Notification\NotificationDispatcher;
use App\Services\Notification\PushSender;
use Illuminate\Support\Facades\Mail;

function makeDispatcher(PushSender $pushSender): NotificationDispatcher
{
    return new NotificationDispatcher(new EmailRenderer, $pushSender, app(ActivityLogger::class));
}

function makePendingNotification(User $user): Notification
{
    return Notification::factory()->create([
        'user_id' => $user->id,
        'sent_at' => null,
        'body_html' => null,
        'event_ids' => [],
        'discovery_event_ids' => [],
    ]);
}

it('emails for the email channel and does not push', function () {
    Mail::fake();
    $user = User::factory()->create(['notification_channel' => NotificationChannel::Email]);
    $notification = makePendingNotification($user);

    $push = Mockery::mock(PushSender::class);
    $push->shouldReceive('sendToUser')->never();

    makeDispatcher($push)->dispatch($notification);

    $notification->refresh();
    expect($notification->sent_at)->not->toBeNull()
        ->and($notification->body_html)->not->toBeNull();
});

it('pushes for the push channel and does not email', function () {
    Mail::fake();
    $user = User::factory()->create(['notification_channel' => NotificationChannel::Push]);
    $notification = makePendingNotification($user);

    $push = Mockery::mock(PushSender::class);
    $push->shouldReceive('sendToUser')->once()->andReturn(1);

    makeDispatcher($push)->dispatch($notification);

    $notification->refresh();
    expect($notification->sent_at)->not->toBeNull()
        ->and($notification->body_html)->toBeNull();
});

it('emails and pushes for the both channel', function () {
    Mail::fake();
    $user = User::factory()->create(['notification_channel' => NotificationChannel::Both]);
    $notification = makePendingNotification($user);

    $push = Mockery::mock(PushSender::class);
    $push->shouldReceive('sendToUser')->once()->andReturn(1);

    makeDispatcher($push)->dispatch($notification);

    $notification->refresh();
    expect($notification->body_html)->not->toBeNull()
        ->and($notification->sent_at)->not->toBeNull();
});

it('does not resend an already-sent notification', function () {
    $user = User::factory()->create(['notification_channel' => NotificationChannel::Push]);
    $notification = Notification::factory()->create([
        'user_id' => $user->id,
        'sent_at' => now(),
    ]);

    $push = Mockery::mock(PushSender::class);
    $push->shouldReceive('sendToUser')->never();

    makeDispatcher($push)->dispatch($notification);

    expect(true)->toBeTrue();
});
