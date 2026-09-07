<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * What a fan-out did, for the dispatcher's log line: web deliveries queued
 * with the push library, native devices handed to the retried job, and web
 * subscriptions skipped because the same handset holds a native device.
 */
final readonly class PushFanoutResult
{
    public function __construct(
        public int $web,
        public int $expo,
        public int $suppressed,
    ) {}

    public function total(): int
    {
        return $this->web + $this->expo;
    }
}
