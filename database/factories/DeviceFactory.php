<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DevicePlatform;
use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'platform' => DevicePlatform::Ios,
            'push_token' => 'ExponentPushToken['.Str::random(22).']',
            'install_id' => (string) Str::uuid(),
            'device_name' => 'Test phone',
            'app_version' => '1.0.0',
            'os_version' => '17.4',
            'locale' => 'ro-RO',
            'timezone' => 'Europe/Bucharest',
            'last_seen_at' => now(),
        ];
    }
}
