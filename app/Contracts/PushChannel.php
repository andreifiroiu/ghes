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
 * surgery on the dispatcher. Called from SendNativePushJob, so a transient
 * failure should throw to be retried; the digest dispatcher never calls it
 * inline.
 */
interface PushChannel
{
    /**
     * Deliver to every registered device of the user. Returns how many
     * deliveries the service accepted.
     *
     * @throws \RuntimeException when the service could not be reached or
     *                           rejected the request — the caller retries
     */
    public function send(User $user, PushPayload $payload): int;
}
