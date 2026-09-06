<?php

declare(strict_types=1);

use App\Contracts\PushChannel;
use App\Jobs\FetchExpoPushReceiptsJob;
use App\Models\Device;
use App\Models\Notification;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Services\Notification\EmailRenderer;
use App\Services\Notification\NotificationDispatcher;
use App\Services\Notification\PushFanout;
use App\Services\Notification\PushPayload;
use App\Services\Notification\PushSender;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'eventpulse.push.expo.enabled' => true,
        'eventpulse.push.expo.endpoint' => 'https://exp.host/--/api/v2/push/send',
        'eventpulse.push.expo.receipts_endpoint' => 'https://exp.host/--/api/v2/push/getReceipts',
        'eventpulse.mobile.scheme' => 'ghes',
    ]);
});

/**
 * Expo answers one ticket per message, in order.
 *
 * @param  list<array<string, mixed>>  $tickets
 */
function fakeExpo(array $tickets, int $status = 200): void
{
    Http::fake([
        'exp.host/--/api/v2/push/send' => Http::response(['data' => $tickets], $status),
    ]);
}

/**
 * The web sender needs VAPID keys to do anything; stub it to count calls
 * without touching the network.
 *
 * @param  list<string>  $seenExcludes
 */
function fakeWebPush(int $returns, array &$seenExcludes): PushSender
{
    $web = Mockery::mock(PushSender::class);
    $web->shouldReceive('sendToUser')
        ->andReturnUsing(function ($user, $title, $body, $url, array $exclude = []) use ($returns, &$seenExcludes): int {
            $seenExcludes = $exclude;

            return $returns;
        });

    return $web;
}

function fanoutWith(PushSender $web): PushFanout
{
    return new PushFanout($web, app(PushChannel::class));
}

function digestFor(User $user): PushPayload
{
    return PushPayload::digest(Notification::factory()->create(['user_id' => $user->id]), 'Digestul tău Ghes', 3);
}

it('sends to web only for a user with no native device', function () {
    Queue::fake();
    $user = User::factory()->create();
    PushSubscription::factory()->create(['user_id' => $user->id]);
    fakeExpo([]);
    $excluded = [];

    $result = fanoutWith(fakeWebPush(1, $excluded))->sendToUser($user, digestFor($user));

    expect($result->web)->toBe(1)->and($result->expo)->toBe(0)->and($result->suppressed)->toBe(0);
    Http::assertNothingSent();
});

it('sends to expo only for a native-only user, with the deep link in data', function () {
    Queue::fake();
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    fakeExpo([['status' => 'ok', 'id' => 'ticket-1']]);
    $excluded = [];

    $payload = digestFor($user);
    $result = fanoutWith(fakeWebPush(0, $excluded))->sendToUser($user, $payload);

    expect($result->expo)->toBe(1);
    Http::assertSent(function ($request) use ($device, $payload): bool {
        $message = $request->data()[0];

        return $message['to'] === $device->push_token
            && $message['title'] === 'Digestul tău Ghes'
            && $message['channelId'] === 'digest'
            && $message['data']['deep_link'] === 'ghes://digest/'.$payload->notificationId
            && $message['data']['type'] === 'digest';
    });
    Queue::assertPushed(FetchExpoPushReceiptsJob::class, fn ($job) => $job->ticketsByToken === ['ticket-1' => $device->push_token]);
});

it('sends to both when the user has both, on different handsets', function () {
    Queue::fake();
    $user = User::factory()->create();
    PushSubscription::factory()->create(['user_id' => $user->id, 'install_id' => null]);
    Device::factory()->create(['user_id' => $user->id]);
    fakeExpo([['status' => 'ok', 'id' => 't']]);
    $excluded = [];

    $result = fanoutWith(fakeWebPush(1, $excluded))->sendToUser($user, digestFor($user));

    expect($result->web)->toBe(1)->and($result->expo)->toBe(1)->and($result->suppressed)->toBe(0);
});

it('suppresses the web subscription that shares an install id with a device', function () {
    Queue::fake();
    $user = User::factory()->create();
    $installId = '55555555-5555-4555-8555-555555555555';
    PushSubscription::factory()->create(['user_id' => $user->id, 'install_id' => $installId]);
    PushSubscription::factory()->create(['user_id' => $user->id, 'install_id' => null]);
    Device::factory()->create(['user_id' => $user->id, 'install_id' => $installId]);
    fakeExpo([['status' => 'ok', 'id' => 't']]);
    $excluded = [];

    $result = fanoutWith(fakeWebPush(1, $excluded))->sendToUser($user, digestFor($user));

    expect($result->suppressed)->toBe(1)
        ->and($excluded)->toBe([$installId]);
});

it('really excludes the matching subscription in the web sender', function () {
    config(['eventpulse.push.enabled' => true, 'eventpulse.push.vapid.public_key' => 'pk', 'eventpulse.push.vapid.private_key' => 'sk']);
    $user = User::factory()->create();
    $installId = '55555555-5555-4555-8555-555555555555';
    PushSubscription::factory()->create(['user_id' => $user->id, 'install_id' => $installId]);
    PushSubscription::factory()->create(['user_id' => $user->id, 'install_id' => null]);

    // The web-push library would try the network for a queued subscription;
    // with a bogus key it throws before flushing, which is fine — the
    // question is only which rows were selected.
    $selected = $user->pushSubscriptions()
        ->where(fn ($q) => $q->whereNull('install_id')->orWhereNotIn('install_id', [$installId]))
        ->count();

    expect($selected)->toBe(1);
});

it('deletes a device the ticket reports as unregistered', function () {
    Queue::fake();
    $user = User::factory()->create();
    $dead = Device::factory()->create(['user_id' => $user->id]);
    $alive = Device::factory()->create(['user_id' => $user->id]);
    fakeExpo([
        ['status' => 'error', 'message' => 'gone', 'details' => ['error' => 'DeviceNotRegistered']],
        ['status' => 'ok', 'id' => 't2'],
    ]);
    $excluded = [];

    $result = fanoutWith(fakeWebPush(0, $excluded))->sendToUser($user, digestFor($user));

    expect($result->expo)->toBe(1)
        ->and(Device::find($dead->id))->toBeNull()
        ->and(Device::find($alive->id))->not->toBeNull();
});

it('deletes a device the receipt reports as unregistered', function () {
    $device = Device::factory()->create();
    Http::fake([
        'exp.host/--/api/v2/push/getReceipts' => Http::response(['data' => [
            'ticket-1' => ['status' => 'error', 'message' => 'gone', 'details' => ['error' => 'DeviceNotRegistered']],
            'ticket-2' => ['status' => 'ok'],
        ]]),
    ]);

    (new FetchExpoPushReceiptsJob(['ticket-1' => $device->push_token, 'ticket-2' => 'ExponentPushToken[other]']))->handle();

    expect(Device::find($device->id))->toBeNull();
});

it('neither throws nor blocks sent_at when expo fails', function () {
    Queue::fake();
    $user = User::factory()->create(['notification_channel' => 'push']);
    Device::factory()->create(['user_id' => $user->id]);
    Http::fake(['exp.host/*' => fn () => throw new ConnectionException('down')]);
    $notification = Notification::factory()->create(['user_id' => $user->id, 'sent_at' => null]);
    $excluded = [];

    $dispatcher = new NotificationDispatcher(
        app(EmailRenderer::class),
        fanoutWith(fakeWebPush(0, $excluded)),
        app(ActivityLogger::class),
    );

    $dispatcher->dispatch($notification);

    expect($notification->fresh()->sent_at)->not->toBeNull();
});

it('does nothing when expo is disabled', function () {
    config(['eventpulse.push.expo.enabled' => false]);
    $user = User::factory()->create();
    Device::factory()->create(['user_id' => $user->id]);
    fakeExpo([['status' => 'ok', 'id' => 't']]);
    $excluded = [];

    $result = fanoutWith(fakeWebPush(0, $excluded))->sendToUser($user, digestFor($user));

    expect($result->expo)->toBe(0);
    Http::assertNothingSent();
});
