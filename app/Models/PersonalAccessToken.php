<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DevicePlatform;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum's token row plus the device it was issued to.
 *
 * @property int $id
 * @property string $name
 * @property array<int, string>|null $abilities
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property string|null $device_id
 * @property string|null $device_name
 * @property DevicePlatform|null $platform
 * @property string|null $app_version
 * @property Carbon|null $signed_in_at
 * @property Carbon|null $created_at
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    public const NAME_ACCESS = 'access';

    public const NAME_REFRESH = 'refresh';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'device_id',
        'device_name',
        'platform',
        'app_version',
        'signed_in_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'abilities' => 'json',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'platform' => DevicePlatform::class,
        'signed_in_at' => 'datetime',
    ];
}
