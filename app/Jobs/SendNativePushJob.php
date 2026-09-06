<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\PushChannel;
use App\Models\User;
use App\Services\Notification\PushPayload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deliver one payload to a user's native devices, with retries.
 *
 * Native push is queued rather than sent inline from the digest dispatcher
 * for two reasons: a push-only user has no email to fall back on, so a
 * transient Expo failure must be retried rather than dropped; and the
 * dispatcher's `sent_at` guarantee (a push failure must never re-send the
 * email) is kept by the queue boundary instead of by swallowing errors.
 */
class SendNativePushJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly string $userId,
        public readonly PushPayload $payload,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(PushChannel $channel): void
    {
        $user = User::find($this->userId);

        // The account was deleted between composing and sending. Nothing
        // to retry.
        if ($user === null) {
            return;
        }

        $channel->send($user, $this->payload);
    }

    public function failed(Throwable $e): void
    {
        Log::error('Native push failed permanently', [
            'user_id' => $this->userId,
            'notification_id' => $this->payload->notificationId,
            'error' => $e->getMessage(),
        ]);
    }
}
