<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Enums\ActivityType;
use App\Enums\NotificationChannel;
use App\Models\Notification;
use App\Services\Activity\ActivityLogger;
use App\Services\Notification\Presenters\NotificationPresenters;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class NotificationDispatcher
{
    public function __construct(
        private readonly NotificationPresenters $presenters,
        private readonly PushFanout $pushFanout,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Render, send, and record a single notification across the user's channel(s).
     *
     * What is said depends on the notification's type and comes from a
     * presenter; the *order* below does not, and must not be duplicated per
     * type — it is the only thing standing between a failed push and a
     * duplicate email.
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
        $presenter = $this->presenters->for($notification);

        // Freshness is checked here rather than at compose time, because a
        // queue backlog is exactly the case where the world moves underneath a
        // composed row. sent_at deliberately stays null: the row then reads as
        // composed-but-never-delivered, and the reminder unique index stops a
        // later run from composing it again.
        if (! $presenter->isStillRelevant($notification)) {
            Log::info("Notification {$notification->id} is no longer relevant, not sending");

            return;
        }

        $channel = $user->notification_channel ?? NotificationChannel::Email;
        $subject = $notification->subject ?? $presenter->subject($notification);

        if (in_array($channel, [NotificationChannel::Email, NotificationChannel::Both], true)) {
            $html = $presenter->renderEmail($notification);
            $notification->update(['subject' => $subject, 'body_html' => $html]);

            $unsubscribeUrl = $presenter->unsubscribeUrl($notification);

            try {
                Mail::html($html, function ($message) use ($user, $subject, $unsubscribeUrl): void {
                    $message->to($user->email)->subject($subject);

                    // Bulk-sender rules at Gmail and Yahoo expect a machine
                    // -readable opt-out; without one the provider's own
                    // "unsubscribe" button turns into a spam report instead.
                    if ($unsubscribeUrl !== null) {
                        $headers = $message->getHeaders();
                        $headers->addTextHeader('List-Unsubscribe', '<'.$unsubscribeUrl.'>');
                        $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
                    }
                });
            } catch (Throwable $e) {
                Log::error("Notification {$notification->id} mail send failed", ['error' => $e->getMessage()]);
                throw $e;
            }
        }

        if (in_array($channel, [NotificationChannel::Push, NotificationChannel::Both], true)) {
            $payload = $presenter->pushPayload($notification, $subject);

            if ($payload !== null) {
                // Never throws — sent_at is set right after this, and an escaping
                // push failure would re-send the email on retry.
                $push = $this->pushFanout->sendToUser($user, $payload);

                Log::info("Notification {$notification->id} push fan-out", [
                    'web' => $push->web,
                    'expo' => $push->expo,
                    'suppressed' => $push->suppressed,
                ]);
            }
        }

        $notification->update(['subject' => $subject, 'sent_at' => now()]);

        // One impression per event the notification actually put in front of
        // someone. Without this the digest contributes clicks (its links
        // resolve through events.go) but no impressions, so the click-through
        // rate divides email clicks by web impressions and can exceed 100%.
        // The surface comes from the presenter so reminder views are never
        // counted into the digest's rate.
        $this->activity->logMany(
            ActivityType::EventImpression,
            $presenter->surface(),
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
