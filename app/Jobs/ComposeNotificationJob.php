<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Services\Notification\NotificationComposer;
use App\Services\Notification\NotificationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ComposeNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly string $userId,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(NotificationComposer $composer, NotificationDispatcher $dispatcher): void
    {
        Log::info('ComposeNotificationJob: composing', ['user_id' => $this->userId]);

        $user = User::findOrFail($this->userId);

        $notification = $composer->compose($user);

        if ($notification !== null) {
            Log::info('ComposeNotificationJob: composed, dispatching send', [
                'user_id' => $this->userId,
                'notification_id' => $notification->id,
            ]);

            SendNotificationJob::dispatch($notification->id);

            return;
        }

        Log::info('ComposeNotificationJob: nothing to send (no events matched)', [
            'user_id' => $this->userId,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('ComposeNotificationJob: failed permanently', [
            'user_id' => $this->userId,
            'error' => $e->getMessage(),
        ]);
    }
}
