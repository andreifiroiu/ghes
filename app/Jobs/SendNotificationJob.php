<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Notification;
use App\Services\Notification\NotificationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600];

    public function __construct(
        public readonly string $notificationId,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(NotificationDispatcher $dispatcher): void
    {
        Log::info('SendNotificationJob: sending', [
            'notification_id' => $this->notificationId,
            'attempt' => $this->attempts(),
        ]);

        $notification = Notification::findOrFail($this->notificationId);

        $dispatcher->dispatch($notification);

        Log::info('SendNotificationJob: sent', [
            'notification_id' => $this->notificationId,
            'user_id' => $notification->user_id,
            'channel' => $notification->channel->value,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('SendNotificationJob: failed permanently', [
            'notification_id' => $this->notificationId,
            'error' => $e->getMessage(),
        ]);
    }
}
