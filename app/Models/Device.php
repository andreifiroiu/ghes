<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DevicePlatform;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An installed native app that can receive push notifications.
 *
 * @property string $id
 * @property string $user_id
 * @property DevicePlatform $platform
 * @property string $push_token
 * @property string|null $install_id
 * @property string|null $device_name
 * @property string|null $app_version
 * @property string|null $os_version
 * @property string|null $locale
 * @property string|null $timezone
 * @property Carbon|null $last_seen_at
 */
class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'platform',
        'push_token',
        'install_id',
        'device_name',
        'app_version',
        'os_version',
        'locale',
        'timezone',
        'last_seen_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => DevicePlatform::class,
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
