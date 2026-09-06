<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * What a verified Google ID token says about the person holding it.
 */
final readonly class GoogleIdentity
{
    public function __construct(
        public string $subject,
        public string $email,
        public ?string $name,
    ) {}
}
