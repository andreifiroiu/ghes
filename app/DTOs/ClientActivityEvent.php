<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\ActivitySurface;
use App\Enums\ActivityType;
use Carbon\CarbonImmutable;

/**
 * One action the native app observed on-device and is reporting after the
 * fact.
 *
 * `id` is the client's own idempotency key. A batch retried after a network
 * blip carries the same ids, and the unique index on
 * `(user_id, client_event_id)` turns the replay into a no-op.
 */
final readonly class ClientActivityEvent
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $id,
        public ActivityType $type,
        public ActivitySurface $surface,
        public ?string $eventId,
        public CarbonImmutable $occurredAt,
        public array $context = [],
    ) {}
}
