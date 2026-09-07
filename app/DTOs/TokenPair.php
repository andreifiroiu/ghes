<?php

declare(strict_types=1);

namespace App\DTOs;

use Illuminate\Support\Carbon;

/**
 * What a sign-in hands the client: a short-lived access token, a long-lived
 * refresh token, and the device id both are bound to.
 */
final readonly class TokenPair
{
    public function __construct(
        public string $accessToken,
        public Carbon $accessExpiresAt,
        public string $refreshToken,
        public Carbon $refreshExpiresAt,
        public string $deviceId,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'access_expires_at' => $this->accessExpiresAt->toIso8601String(),
            'refresh_token' => $this->refreshToken,
            'refresh_expires_at' => $this->refreshExpiresAt->toIso8601String(),
            'device_id' => $this->deviceId,
        ];
    }
}
