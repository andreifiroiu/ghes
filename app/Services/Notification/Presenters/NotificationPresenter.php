<?php

declare(strict_types=1);

namespace App\Services\Notification\Presenters;

use App\Enums\ActivitySurface;
use App\Models\Notification;
use App\Services\Notification\PushPayload;

/**
 * Everything that differs between one kind of notification and another.
 *
 * NotificationDispatcher keeps sole ownership of the send *ordering* — the
 * "push never throws, sent_at is written after it" invariant has exactly one
 * home and must not be restated per type. A presenter only answers what to say
 * and whether it is still worth saying.
 */
interface NotificationPresenter
{
    /**
     * False when the notification is no longer worth sending.
     *
     * A digest is always relevant. A reminder composed 20 minutes ago for an
     * event that has since started, moved or been hidden is not — and the
     * dispatcher leaves `sent_at` null when this returns false, so the row
     * reads honestly as composed-but-never-delivered.
     */
    public function isStillRelevant(Notification $notification): bool;

    /**
     * Subject line, used for the email and as the push title.
     */
    public function subject(Notification $notification): string;

    public function renderEmail(Notification $notification): string;

    /**
     * Null when this notification should not push at all — a reminder inside
     * the configured quiet hours, for instance. The email still goes.
     */
    public function pushPayload(Notification $notification, string $subject): ?PushPayload;

    /**
     * A signed one-click opt-out for this kind of mail, or null when the kind
     * has no separate opt-out. Used for both the footer link and the
     * List-Unsubscribe header, so the two cannot disagree.
     */
    public function unsubscribeUrl(Notification $notification): ?string;

    /**
     * The surface impressions from this notification are attributed to, so the
     * digest's click-through rate is not computed against reminder views.
     */
    public function surface(): ActivitySurface;
}
