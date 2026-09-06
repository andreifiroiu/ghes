<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\DTOs\DeviceContext;
use App\DTOs\TokenPair;
use App\Enums\TokenAbility;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The one place tokens are minted, so register, login, refresh and (later)
 * native OAuth cannot drift on abilities, lifetimes or device binding.
 *
 * Per-token `expires_at` is what enforces lifetimes. `config/sanctum.php`'s
 * global `expiration` must stay null: it overrides every token's own expiry
 * and would silently kill refresh tokens after an hour.
 */
class TokenIssuer
{
    /**
     * Issue a fresh pair for a device, replacing whatever that device held.
     *
     * Replacing rather than adding is what keeps a re-login from leaving the
     * previous pair alive, and what makes refresh a rotation: the old pair is
     * gone the moment the new one exists.
     */
    public function issuePair(User $user, DeviceContext $device): TokenPair
    {
        return DB::transaction(fn (): TokenPair => $this->mint($user, $device));
    }

    /**
     * Rotate the pair a refresh token belongs to, or null when that token
     * no longer exists.
     *
     * The row is re-checked under a lock inside the transaction: two refresh
     * calls racing from one device both authenticate against the same row,
     * and without this the second would revoke the pair the first had just
     * issued and hand out a third — leaving the client holding whichever
     * response arrived last, possibly the dead one. The loser now sees the
     * row gone and answers 401, which is the same outcome as a replay.
     */
    public function rotate(User $user, PersonalAccessToken $refreshToken): ?TokenPair
    {
        if ($refreshToken->device_id === null) {
            return null;
        }

        return DB::transaction(function () use ($user, $refreshToken): ?TokenPair {
            $stillHeld = $user->tokens()
                ->whereKey($refreshToken->getKey())
                ->lockForUpdate()
                ->exists();

            if (! $stillHeld) {
                return null;
            }

            return $this->mint($user, DeviceContext::fromToken($refreshToken));
        });
    }

    /**
     * Replace the device's tokens with a fresh pair. Caller holds the transaction.
     */
    private function mint(User $user, DeviceContext $device): TokenPair
    {
        // Lock the device's rows before replacing them, so two sign-ins or
        // refreshes racing on one device serialise: the second sees the
        // first's committed pair and replaces it, rather than both inserting
        // and leaving the device holding two live pairs.
        $existing = $user->tokens()
            ->where('device_id', $device->deviceId)
            ->lockForUpdate()
            ->get();

        /** @var Carbon $signedInAt */
        $signedInAt = $existing->min('signed_in_at') ?? Carbon::now();

        $this->revokeDevice($user, $device->deviceId);

        $accessExpiresAt = Carbon::now()->addMinutes(
            (int) config('eventpulse.api.tokens.access_ttl_minutes', 60)
        );
        $refreshExpiresAt = Carbon::now()->addDays(
            (int) config('eventpulse.api.tokens.refresh_ttl_days', 60)
        );

        $accessAbilities = [TokenAbility::AccessApi->value];

        if (Gate::forUser($user)->allows('access-admin')) {
            $accessAbilities[] = TokenAbility::Admin->value;
        }

        return new TokenPair(
            accessToken: $this->create($user, $device, PersonalAccessToken::NAME_ACCESS, $accessAbilities, $accessExpiresAt, $signedInAt),
            accessExpiresAt: $accessExpiresAt,
            refreshToken: $this->create($user, $device, PersonalAccessToken::NAME_REFRESH, [TokenAbility::RefreshToken->value], $refreshExpiresAt, $signedInAt),
            refreshExpiresAt: $refreshExpiresAt,
            deviceId: $device->deviceId,
        );
    }

    /**
     * Revoke every token bound to one device. Returns how many were removed.
     */
    public function revokeDevice(User $user, string $deviceId): int
    {
        return $user->tokens()->where('device_id', $deviceId)->delete();
    }

    /**
     * Revoke every token the user has, on every device.
     */
    public function revokeAll(User $user): int
    {
        return $user->tokens()->delete();
    }

    /**
     * Mirror of Sanctum's createToken() with the device columns written in
     * the same insert. Returns the plain-text token the client will send.
     *
     * @param  list<string>  $abilities
     */
    private function create(User $user, DeviceContext $device, string $name, array $abilities, Carbon $expiresAt, Carbon $signedInAt): string
    {
        $plainText = sprintf(
            '%s%s%s',
            config('sanctum.token_prefix', ''),
            $entropy = Str::random(40),
            hash('crc32b', $entropy),
        );

        /** @var PersonalAccessToken $token */
        $token = $user->tokens()->create([
            'name' => $name,
            'token' => hash('sha256', $plainText),
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
            'device_id' => $device->deviceId,
            'device_name' => $device->deviceName,
            'platform' => $device->platform,
            'app_version' => $device->appVersion,

            'signed_in_at' => $signedInAt,
        ]);

        return $token->getKey().'|'.$plainText;
    }
}
