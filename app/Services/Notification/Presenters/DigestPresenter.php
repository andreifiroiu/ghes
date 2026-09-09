<?php

declare(strict_types=1);

namespace App\Services\Notification\Presenters;

use App\Enums\ActivitySurface;
use App\Models\Notification;
use App\Services\Notification\EmailRenderer;
use App\Services\Notification\PushPayload;

/**
 * The daily/weekly recommendation batch — the only kind of notification that
 * existed before reminders, and behaviourally unchanged by their arrival.
 */
class DigestPresenter implements NotificationPresenter
{
    public function __construct(
        private readonly EmailRenderer $emailRenderer,
    ) {}

    /**
     * A digest is a snapshot of what was recommended when it was composed;
     * nothing about the world can make it wrong between compose and send.
     */
    public function isStillRelevant(Notification $notification): bool
    {
        return true;
    }

    public function subject(Notification $notification): string
    {
        return 'Digestul tău Ghes';
    }

    public function renderEmail(Notification $notification): string
    {
        return $this->emailRenderer->render($notification);
    }

    public function pushPayload(Notification $notification, string $subject): ?PushPayload
    {
        $eventCount = count($notification->event_ids ?? []) + count($notification->discovery_event_ids ?? []);

        return PushPayload::digest($notification, $subject, $eventCount);
    }

    /**
     * None yet. The digest is what someone signs up for, and there is no
     * "digest off" state in the data model to point an unsubscribe link at —
     * notification_channel has no `none`. Adding one is its own change.
     */
    public function unsubscribeUrl(Notification $notification): ?string
    {
        return null;
    }

    public function surface(): ActivitySurface
    {
        return ActivitySurface::Digest;
    }
}
