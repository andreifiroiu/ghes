<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class PushSender
{
    /**
     * Send a push notification to all of a user's registered subscriptions.
     *
     * Returns the number of subscriptions the message was queued for. Safely
     * no-ops when push is disabled, VAPID keys are missing, or the user has no
     * subscriptions. Expired/gone subscriptions are pruned.
     */
    public function sendToUser(User $user, string $title, string $body, ?string $url = null): int
    {
        if (! $this->isConfigured()) {
            return 0;
        }

        $subscriptions = $user->pushSubscriptions()->get();

        if ($subscriptions->isEmpty()) {
            return 0;
        }

        $webPush = new WebPush(['VAPID' => [
            'subject' => (string) config('eventpulse.push.vapid.subject'),
            'publicKey' => (string) config('eventpulse.push.vapid.public_key'),
            'privateKey' => (string) config('eventpulse.push.vapid.private_key'),
        ]]);

        $payload = (string) json_encode(array_filter([
            'title' => $title,
            'body' => $body,
            'url' => $url,
        ], fn ($value) => $value !== null));

        $queued = 0;

        foreach ($subscriptions as $subscription) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'publicKey' => $subscription->public_key,
                    'authToken' => $subscription->auth_token,
                    'contentEncoding' => $subscription->content_encoding ?? 'aesgcm',
                ]),
                $payload,
            );
            $queued++;
        }

        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                continue;
            }

            Log::warning('Push delivery failed', [
                'endpoint' => $report->getEndpoint(),
                'reason' => $report->getReason(),
            ]);

            if ($report->isSubscriptionExpired()) {
                PushSubscription::where('endpoint', $report->getEndpoint())->delete();
            }
        }

        return $queued;
    }

    /**
     * Whether push is enabled and VAPID keys are configured.
     */
    public function isConfigured(): bool
    {
        return (bool) config('eventpulse.push.enabled')
            && (string) config('eventpulse.push.vapid.public_key') !== ''
            && (string) config('eventpulse.push.vapid.private_key') !== '';
    }
}
