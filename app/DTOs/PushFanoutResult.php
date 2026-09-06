<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * What a fan-out delivered, for the dispatcher's log line.
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
