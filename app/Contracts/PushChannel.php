<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\User;
use App\Services\Notification\PushPayload;

/**
 * A native push delivery channel.
 *
 * One implementation today (Expo). Kept behind an interface so a later move
 * to FCM v1 / APNs directly is a new class bound in the container, not
 * surgery on the dispatcher. Implementations must never throw: the digest
 * dispatcher sets `sent_at` after the push branch, so an escaping exception
 * would re-send the email on retry.
 */
interface PushChannel
{
    /**
     * Deliver to every registered device of the user. Returns how many
     * deliveries were accepted by the service.
     */
    public function send(User $user, PushPayload $payload): int;
}
