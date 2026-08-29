<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LogsConsoleOutput;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notification\NotificationComposer;
use App\Services\Notification\NotificationDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class SendNotificationsCommand extends Command
{
    use LogsConsoleOutput;

    protected $signature = 'eventpulse:send-notifications
        {--user= : Send to a specific user UUID}
        {--sync : Deliver inline instead of queueing, for verifying delivery without a worker}';

    protected $description = 'Compose event notification digests and queue them for delivery';

    public function handle(NotificationComposer $composer, NotificationDispatcher $dispatcher): int
    {
        $userId = $this->option('user');

        if (is_string($userId)) {
            $user = User::findOrFail($userId);
            $this->info("Composing notification for user: {$user->email}");

            $notification = $composer->compose($user);

            if ($notification === null) {
                $this->warn('No events to recommend for this user.');

                return self::SUCCESS;
            }

            $this->deliver(collect([$notification]), $dispatcher);

            return self::SUCCESS;
        }

        $this->info('Composing notifications for all due users...');

        $notifications = $composer->composeForAll();

        if ($notifications->isEmpty()) {
            $this->info('No users are due for notifications.');

            return self::SUCCESS;
        }

        $this->deliver($notifications, $dispatcher);

        return self::SUCCESS;
    }

    /**
     * Hand the composed digests to the queue, or send them inline under --sync.
     *
     * Queueing is the default because delivery is an outbound API call per
     * recipient: sending inline made one blocking Mailgun request per user
     * inside the scheduled run, and dispatchBatch() swallows a failure as a
     * miss, so a single 429 cost that user their digest with no retry.
     * SendNotificationJob already carries tries = 3 and a backoff.
     *
     * @param  Collection<int, Notification>  $notifications
     */
    private function deliver(Collection $notifications, NotificationDispatcher $dispatcher): void
    {
        $count = $notifications->count();

        if ($this->option('sync')) {
            $this->info("Sending {$count} notifications inline...");

            $sent = $dispatcher->dispatchBatch($notifications);

            $this->info("Successfully sent {$sent}/{$count} notifications.");

            return;
        }

        foreach ($notifications as $notification) {
            SendNotificationJob::dispatch($notification->id);
        }

        $this->info("Queued {$count} notifications on the notifications queue.");
    }
}
