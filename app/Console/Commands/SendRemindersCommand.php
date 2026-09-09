<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LogsConsoleOutput;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notification\NotificationDispatcher;
use App\Services\Notification\ReminderComposer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class SendRemindersCommand extends Command
{
    use LogsConsoleOutput;

    protected $signature = 'eventpulse:send-reminders
        {--user= : Compose only for a specific user UUID}
        {--sync : Deliver inline instead of queueing, for verifying delivery without a worker}';

    protected $description = 'Compose reminders for events starting soon that users saved or marked interested';

    public function handle(ReminderComposer $composer, NotificationDispatcher $dispatcher): int
    {
        $userId = $this->option('user');
        $only = is_string($userId) ? User::findOrFail($userId) : null;

        $notifications = $composer->composeDue($only);

        if ($notifications->isEmpty()) {
            $this->info('No reminders are due.');

            return self::SUCCESS;
        }

        $this->deliver($notifications, $dispatcher);

        return self::SUCCESS;
    }

    /**
     * Hand the composed reminders to the queue, or send them inline under --sync.
     *
     * Queued by default for the same reason the digest is: delivery is an
     * outbound API call per recipient, and dispatchBatch() swallows a failure
     * as a miss, so a single 429 would cost someone their reminder with no
     * retry. The unique index makes a retry safe.
     *
     * @param  Collection<int, Notification>  $notifications
     */
    private function deliver(Collection $notifications, NotificationDispatcher $dispatcher): void
    {
        $count = $notifications->count();

        if ($this->option('sync')) {
            $this->info("Sending {$count} reminders inline...");

            $sent = $dispatcher->dispatchBatch($notifications);

            $this->info("Successfully sent {$sent}/{$count} reminders.");

            return;
        }

        foreach ($notifications as $notification) {
            SendNotificationJob::dispatch($notification->id);
        }

        $this->info("Queued {$count} reminders on the notifications queue.");
    }
}
