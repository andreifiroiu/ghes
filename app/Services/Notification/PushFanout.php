<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Contracts\PushChannel;
use App\DTOs\PushFanoutResult;
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
 */
class PushFanout
{
    public function __construct(
        private readonly PushSender $web,
        private readonly PushChannel $native,
    ) {}

    public function sendToUser(User $user, PushPayload $payload): PushFanoutResult
    {
        $installIds = $user->devices()->whereNotNull('install_id')->pluck('install_id')->all();

        $suppressed = $installIds === []
            ? 0
            : $user->pushSubscriptions()->whereIn('install_id', $installIds)->count();

        $web = 0;
        $expo = 0;

        // Each channel already swallows its own failures; this is the belt
        // for the dispatcher's sent_at guarantee.
        try {
            $web = $this->web->sendToUser($user, $payload->title, $payload->body, $payload->url, $installIds);
        } catch (Throwable $e) {
            Log::error('Web push fan-out failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        try {
            $expo = $this->native->send($user, $payload);
        } catch (Throwable $e) {
            Log::error('Native push fan-out failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        return new PushFanoutResult(web: $web, expo: $expo, suppressed: $suppressed);
    }
}
