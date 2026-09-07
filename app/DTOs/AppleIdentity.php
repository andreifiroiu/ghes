<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * What a verified Apple ID token says. Apple includes the address on the
 * first sign-in only, and may hand out a private relay address.
 */
final readonly class AppleIdentity
{
    public function __construct(
        public string $subject,
        public ?string $email,
        public bool $emailVerified,
        public bool $isPrivateEmail,
    ) {}
}
