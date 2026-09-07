<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\DevicePlatform;
use App\Models\PersonalAccessToken;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * The installed app a token pair is issued to.
 *
 * `deviceId` is a stable, client-persisted UUID. The server mints one on the
 * first sign-in from an install that has none and returns it in the pair,
 * so the client can send it back next time and its old tokens get replaced
 * instead of piling up.
 */
final readonly class DeviceContext
{
    public function __construct(
        public string $deviceId,
        public string $deviceName,
        public DevicePlatform $platform,
        public ?string $appVersion = null,
    ) {}

    /**
     * Validation rules for the device fields of a sign-in request.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'device_id' => ['sometimes', 'nullable', 'uuid'],
            'device_name' => ['required', 'string', 'max:100'],
            'platform' => ['required', Rule::enum(DevicePlatform::class)],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^v?\d+(?:\.\d+){0,3}(?:[-+].*)?$/i'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $deviceId = $validated['device_id'] ?? null;

        return new self(
            deviceId: is_string($deviceId) && $deviceId !== '' ? $deviceId : (string) Str::uuid(),
            deviceName: (string) $validated['device_name'],
            platform: DevicePlatform::from((string) $validated['platform']),
            appVersion: isset($validated['app_version']) ? (string) $validated['app_version'] : null,
        );
    }

    /**
     * The device a token was issued to, for rotating its pair.
     *
     * Every token minted by TokenIssuer carries a device, and the migration
     * that added the columns revoked the ones that predate them, so a token
     * without one is a programming error — never papered over with defaults,
     * which would mint a pair for a phantom device.
     */
    public static function fromToken(PersonalAccessToken $token): self
    {
        if ($token->device_id === null || $token->device_name === null || $token->platform === null) {
            throw new LogicException('Token '.$token->getKey().' is not bound to a device.');
        }

        return new self(
            deviceId: $token->device_id,
            deviceName: $token->device_name,
            platform: $token->platform,
            appVersion: $token->app_version,
        );
    }
}
