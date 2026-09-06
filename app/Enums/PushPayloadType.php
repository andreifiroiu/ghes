<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a push notification is about; doubles as the Android channel id.
 */
enum PushPayloadType: string
{
    case Digest = 'digest';
    case Event = 'event';
    case Profile = 'profile';
}
