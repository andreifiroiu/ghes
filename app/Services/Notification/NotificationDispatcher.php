<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Enums\ActivitySurface;
use App\Enums\ActivityType;
use App\Enums\NotificationChannel;
use App\Models\Notification;
use App\Services\Activity\ActivityLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class NotificationDispatcher
{
    public function __construct(
        private readonly EmailRenderer $emailRenderer,
        private readonly PushSender $pushSender,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Render, send, and record a single notification across the user's channel(s).
     */
    public function dispatch(Notification $notification): void
    {
        // Guard against duplicate sends (e.g. job retry after mail succeeded but DB update failed)
        if ($notification->sent_at !== null) {
            Log::info("Notification {$notification->id} already sent, skipping");

            return;
        }

        $notification->loadMissing('user');
        $user = $notification->user;
        $channel = $user->notification_channel ?? NotificationChannel::Email;

        $subject = $notification->subject ?? 'Digestul tău Ghes';

        if (in_array($channel, [NotificationChannel::Email, NotificationChannel::Both], true)) {
            $html = $this->emailRenderer->render($notification);
            $notification->update(['body_html' => $html]);

            try {
                Mail::html($html, function ($message) use ($user, $subject): void {
                    $message->to($user->email)->subject($subject);
                });
            } catch (Throwable $e) {
                Log::error("Notification {$notification->id} mail send failed", ['error' => $e->getMessage()]);
                throw $e;
            }
        }

        if (in_array($channel, [NotificationChannel::Push, NotificationChannel::Both], true)) {
            $eventCount = count($notification->event_ids ?? []) + count($notification->discovery_event_ids ?? []);
            $this->pushSender->sendToUser(
                $user,
                $subject,
                "Ai {$eventCount} evenimente noi recomandate pentru tine.",
                route('dashboard'),
            );
        }

        $notification->update(['sent_at' => now()]);

        // One impression per event the digest actually put in front of someone.
        // Without this the digest contributes clicks (its links resolve through
        // events.go) but no impressions, so the click-through rate divides
        // email clicks by web impressions and can exceed 100%.
        $this->activity->logMany(
            ActivityType::EventImpression,
            ActivitySurface::Digest,
            [...($notification->event_ids ?? []), ...($notification->discovery_event_ids ?? [])],
            $user,
            $notification->id,
            serverOriginated: true,
        );

        Log::info("Notification {$notification->id} sent to user {$user->id} via {$channel->value}");
    }

    /**
     * Dispatch a batch of notifications. Failures are logged, not thrown.
     *
     * @param  Collection<int, Notification>  $notifications
     * @return int Number of successfully sent notifications.
     */
    public function dispatchBatch(Collection $notifications): int
    {
        $sent = 0;

        foreach ($notifications as $notification) {
            try {
                $this->dispatch($notification);
                $sent++;
            } catch (Throwable $e) {
                Log::error('Failed to dispatch notification', [
                    'notification_id' => $notification->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info("Dispatched {$sent}/{$notifications->count()} notifications");

        return $sent;
    }
}
