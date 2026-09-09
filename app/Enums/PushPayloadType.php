<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a push notification is about. The native client switches on this value
 * in the payload's `data` block.
 */
enum PushPayloadType: string
{
    case Digest = 'digest';
    case Event = 'event';
    case Profile = 'profile';
    case Reminder = 'reminder';

    /**
     * The Android notification channel this payload should land in.
     *
     * Deliberately a mapping rather than the backing value itself. The client
     * registers exactly three channels at startup — `digest`, `reminders` and
     * `system` — so sending a payload type as the channel id silently drops
     * anything else into Android's default importance. Naming the mapping
     * makes that visible and lets the enum stay readable in PHP: `Reminder`
     * here, the plural `reminders` channel there.
     */
    public function androidChannel(): string
    {
        return match ($this) {
            self::Digest => 'digest',
            self::Reminder => 'reminders',
            self::Event, self::Profile => 'system',
        };
    }
}
