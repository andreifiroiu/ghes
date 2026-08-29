<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LogsConsoleOutput;
use App\Models\User;
use App\Services\Notification\NotificationComposer;
use App\Services\Notification\NotificationDispatcher;
use Illuminate\Console\Command;

class SendNotificationsCommand extends Command
{
    use LogsConsoleOutput;

    protected $signature = 'eventpulse:send-notifications {--user= : Send to a specific user UUID}';

    protected $description = 'Compose and send event notification digests to users';

    public function handle(NotificationComposer $composer, NotificationDispatcher $dispatcher): int
    {
        $userId = $this->option('user');

        if ($userId) {
            $user = User::findOrFail($userId);
            $this->info("Composing notification for user: {$user->email}");

            $notification = $composer->compose($user);

            if ($notification === null) {
                $this->warn('No events to recommend for this user.');

                return self::SUCCESS;
            }

            $dispatcher->dispatch($notification);
            $this->info('Notification sent successfully.');

            return self::SUCCESS;
        }

        $this->info('Composing notifications for all due users...');

        $notifications = $composer->composeForAll();

        if ($notifications->isEmpty()) {
            $this->info('No users are due for notifications.');

            return self::SUCCESS;
        }

        $this->info("Dispatching {$notifications->count()} notifications...");

        $sent = $dispatcher->dispatchBatch($notifications);

        $this->info("Successfully sent {$sent}/{$notifications->count()} notifications.");

        return self::SUCCESS;
    }
}
