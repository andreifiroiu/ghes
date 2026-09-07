<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\DTOs\PushFanoutResult;
use App\Jobs\SendNativePushJob;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one payload to every push target a user has: web subscriptions and
 * native devices are different handsets and both get it.
 *
 * The one true duplicate is the same handset registered twice — browser
 * and native app — and there is no join between a web endpoint and a push
 * token. Both clients therefore send a persisted `install_id`, and a web
 * subscription whose install id matches one of the user's devices is
 * suppressed. Legacy subscriptions carry no install id and are never
 * suppressed, which is right: a desktop browser is a different device.
 *
 * Web push is sent inline (the library swallows delivery errors); native
 * push is queued with retries, because a push-only user has no email to
 * fall back on. Neither path may throw: the digest dispatcher sets
 * `sent_at` right after this, and an escaping exception would re-send the
 * email on retry.
 */
class PushFanout
{
    public function __construct(
        private readonly PushSender $web,
    ) {}

    public function sendToUser(User $user, PushPayload $payload): PushFanoutResult
    {
        $installIds = $user->devices()->whereNotNull('install_id')->pluck('install_id')->all();
        $deviceCount = $user->devices()->count();

        $suppressed = $installIds === []
            ? 0
            : $user->pushSubscriptions()->whereIn('install_id', $installIds)->count();

        $web = 0;
        $expo = 0;

        try {
            $web = $this->web->sendToUser($user, $payload->title, $payload->body, $payload->url, $installIds);
        } catch (Throwable $e) {
            Log::error('Web push fan-out failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        if ($deviceCount > 0 && (bool) config('eventpulse.push.expo.enabled')) {
            // With a sync queue driver the job runs here and a failure would
            // surface as an exception; with Redis this only enqueues. Caught
            // either way so the dispatcher's guarantee does not depend on
            // the driver.
            try {
                SendNativePushJob::dispatch($user->id, $payload);
                $expo = $deviceCount;
            } catch (Throwable $e) {
                Log::error('Native push fan-out failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }

        return new PushFanoutResult(web: $web, expo: $expo, suppressed: $suppressed);
    }
}
