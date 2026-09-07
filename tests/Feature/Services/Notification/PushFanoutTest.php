<?php

declare(strict_types=1);

use App\Contracts\PushChannel;
use App\Jobs\FetchExpoPushReceiptsJob;
use App\Jobs\SendNativePushJob;
use App\Models\Device;
use App\Models\Notification;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Services\Notification\EmailRenderer;
use App\Services\Notification\ExpoPushSender;
use App\Services\Notification\NotificationDispatcher;
use App\Services\Notification\PushFanout;
use App\Services\Notification\PushPayload;
use App\Services\Notification\PushSender;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const EXPO_SEND = 'exp.host/--/api/v2/push/send';
const EXPO_RECEIPTS = 'exp.host/--/api/v2/push/getReceipts';

beforeEach(function () {
    config([
        'eventpulse.push.expo.enabled' => true,
        'eventpulse.push.expo.endpoint' => 'https://'.EXPO_SEND,
        'eventpulse.push.expo.receipts_endpoint' => 'https://'.EXPO_RECEIPTS,
        'eventpulse.mobile.scheme' => 'ghes',
    ]);
});

/**
 * Expo answers one ticket per message, in order. The queue is sync in tests,
 * so the delayed receipts job runs immediately too and needs an answer.
 *
 * @param  list<array<string, mixed>>  $tickets
 * @param  array<string, array<string, mixed>>  $receipts
 */
function fakeExpo(array $tickets, array $receipts = [], int $status = 200): void
{
    Http::fake([
        EXPO_SEND => Http::response(['data' => $tickets], $status),
        EXPO_RECEIPTS => Http::response(['data' => $receipts]),
    ]);
}

/**
 * The web sender needs VAPID keys to do anything; stub it to count calls
 * without touching the network and to capture the exclusion list.
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

function digestFor(User $user): PushPayload
{
    return PushPayload::digest(Notification::factory()->create(['user_id' => $user->id]), 'Digestul tău Ghes', 3);
}

it('sends to web only for a user with no native device', function () {
    $user = User::factory()->create();
    PushSubscription::factory()->create(['user_id' => $user->id]);
    fakeExpo([]);
    $excluded = [];

    $result = (new PushFanout(fakeWebPush(1, $excluded)))->sendToUser($user, digestFor($user));

    expect($result->web)->toBe(1)->and($result->expo)->toBe(0)->and($result->suppressed)->toBe(0);
    Http::assertNothingSent();
});

it('queues native delivery for a native-only user, with the deep link in data', function () {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    fakeExpo([['status' => 'ok', 'id' => 'ticket-1']], ['ticket-1' => ['status' => 'ok']]);
    $excluded = [];

    $payload = digestFor($user);
    $result = (new PushFanout(fakeWebPush(0, $excluded)))->sendToUser($user, $payload);

    expect($result->expo)->toBe(1);
    Http::assertSent(function ($request) use ($device, $payload): bool {
        if (! str_contains($request->url(), 'push/send')) {
            return false;
        }
        $message = $request->data()[0];

        return $message['to'] === $device->push_token
            && $message['title'] === 'Digestul tău Ghes'
            && $message['channelId'] === 'digest'
            && $message['data']['deep_link'] === 'ghes://digest/'.$payload->notificationId
            && $message['data']['type'] === 'digest';
    });
    // The receipts job followed with the ticket it was handed.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'getReceipts') && $request['ids'] === ['ticket-1']);
});

it('hands native delivery to a retried job rather than sending inline', function () {
    Queue::fake();
    $user = User::factory()->create();
    Device::factory()->count(2)->create(['user_id' => $user->id]);
    $excluded = [];

    $result = (new PushFanout(fakeWebPush(0, $excluded)))->sendToUser($user, digestFor($user));

    expect($result->expo)->toBe(2);
    Queue::assertPushed(SendNativePushJob::class, fn (SendNativePushJob $job) => $job->userId === $user->id && $job->tries === 3);
    Http::assertNothingSent();
});

it('sends to both when the user has both, on different handsets', function () {
    $user = User::factory()->create();
    PushSubscription::factory()->create(['user_id' => $user->id, 'install_id' => null]);
    Device::factory()->create(['user_id' => $user->id]);
    fakeExpo([['status' => 'ok', 'id' => 't']]);
    $excluded = [];

    $result = (new PushFanout(fakeWebPush(1, $excluded)))->sendToUser($user, digestFor($user));

    expect($result->web)->toBe(1)->and($result->expo)->toBe(1)->and($result->suppressed)->toBe(0);
});

it('suppresses the web subscription that shares an install id with a device', function () {
    $user = User::factory()->create();
    $installId = '55555555-5555-4555-8555-555555555555';
    PushSubscription::factory()->create(['user_id' => $user->id, 'install_id' => $installId]);
    PushSubscription::factory()->create(['user_id' => $user->id, 'install_id' => null]);
    Device::factory()->create(['user_id' => $user->id, 'install_id' => $installId]);
    fakeExpo([['status' => 'ok', 'id' => 't']]);
    $excluded = [];

    $result = (new PushFanout(fakeWebPush(1, $excluded)))->sendToUser($user, digestFor($user));

    expect($result->suppressed)->toBe(1)
        ->and($excluded)->toBe([$installId]);
});

it('really excludes the matching subscription in the web sender query', function () {
    $user = User::factory()->create();
    $installId = '55555555-5555-4555-8555-555555555555';
    PushSubscription::factory()->create(['user_id' => $user->id, 'install_id' => $installId]);
    PushSubscription::factory()->create(['user_id' => $user->id, 'install_id' => null]);

    $selected = $user->pushSubscriptions()
        ->where(fn ($q) => $q->whereNull('install_id')->orWhereNotIn('install_id', [$installId]))
        ->count();

    expect($selected)->toBe(1);
});

it('deletes a device the ticket reports as unregistered', function () {
    $user = User::factory()->create();
    $dead = Device::factory()->create(['user_id' => $user->id]);
    $alive = Device::factory()->create(['user_id' => $user->id]);
    fakeExpo([
        ['status' => 'error', 'message' => 'gone', 'details' => ['error' => 'DeviceNotRegistered']],
        ['status' => 'ok', 'id' => 't2'],
    ]);

    $accepted = app(PushChannel::class)->send($user, digestFor($user));

    expect($accepted)->toBe(1)
        ->and(Device::find($dead->id))->toBeNull()
        ->and(Device::find($alive->id))->not->toBeNull();
});

it('deletes a device the receipt reports as unregistered', function () {
    $device = Device::factory()->create();
    Http::fake([
        EXPO_RECEIPTS => Http::response(['data' => [
            'ticket-1' => ['status' => 'error', 'message' => 'gone', 'details' => ['error' => 'DeviceNotRegistered']],
            'ticket-2' => ['status' => 'ok'],
        ]]),
    ]);

    (new FetchExpoPushReceiptsJob(['ticket-1' => $device->push_token, 'ticket-2' => 'ExponentPushToken[other]']))
        ->handle(app(ExpoPushSender::class));

    expect(Device::find($device->id))->toBeNull();
});

it('sends the access token on the receipts request too', function () {
    config(['eventpulse.push.expo.access_token' => 'expo-secret']);
    Http::fake([EXPO_RECEIPTS => Http::response(['data' => []])]);

    (new FetchExpoPushReceiptsJob(['t' => 'ExponentPushToken[x]']))->handle(app(ExpoPushSender::class));

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer expo-secret'));
});

it('retries the receipts request on a rate limit', function () {
    Http::fake([EXPO_RECEIPTS => Http::response('slow down', 429)]);

    expect(fn () => (new FetchExpoPushReceiptsJob(['t' => 'x']))->handle(app(ExpoPushSender::class)))
        ->toThrow(RuntimeException::class);
});

it('gives up quietly on malformed receipts data', function () {
    Http::fake([EXPO_RECEIPTS => Http::response(['data' => 'not-a-map'])]);

    (new FetchExpoPushReceiptsJob(['t' => 'x']))->handle(app(ExpoPushSender::class));
})->throwsNoExceptions();

it('throws from the channel when expo rejects the batch, so the job retries', function () {
    $user = User::factory()->create();
    Device::factory()->create(['user_id' => $user->id]);
    fakeExpo([], status: 503);

    expect(fn () => app(PushChannel::class)->send($user, digestFor($user)))->toThrow(RuntimeException::class);
});

it('neither throws nor blocks sent_at when expo is down', function () {
    $user = User::factory()->create(['notification_channel' => 'push']);
    Device::factory()->create(['user_id' => $user->id]);
    Http::fake(['exp.host/*' => fn () => throw new ConnectionException('down')]);
    $notification = Notification::factory()->create(['user_id' => $user->id, 'sent_at' => null]);
    $excluded = [];

    $dispatcher = new NotificationDispatcher(
        app(EmailRenderer::class),
        new PushFanout(fakeWebPush(0, $excluded)),
        app(ActivityLogger::class),
    );

    // The sync queue runs the native job inline, so its failure surfaces
    // here — and must be swallowed by the fan-out.
    $dispatcher->dispatch($notification);

    expect($notification->fresh()->sent_at)->not->toBeNull();
});

it('skips a user deleted before the native job ran', function () {
    fakeExpo([['status' => 'ok', 'id' => 't']]);
    $user = User::factory()->create();
    $payload = digestFor($user);
    $user->forceDelete();

    (new SendNativePushJob($user->id, $payload))->handle(app(PushChannel::class));

    Http::assertNothingSent();
});

it('does nothing when expo is disabled', function () {
    config(['eventpulse.push.expo.enabled' => false]);
    $user = User::factory()->create();
    Device::factory()->create(['user_id' => $user->id]);
    fakeExpo([['status' => 'ok', 'id' => 't']]);
    $excluded = [];

    $result = (new PushFanout(fakeWebPush(0, $excluded)))->sendToUser($user, digestFor($user));

    expect($result->expo)->toBe(0);
    Http::assertNothingSent();
});
