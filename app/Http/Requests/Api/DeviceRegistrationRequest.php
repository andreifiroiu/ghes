<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Enums\DevicePlatform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeviceRegistrationRequest extends FormRequest
{
    /**
     * Expo tokens look like `ExponentPushToken[xxx]` (or the older
     * `ExpoPushToken[xxx]`). Anything else is not something the Expo
     * service can deliver to, and would be a client sending a raw FCM/APNs
     * token to the wrong channel.
     */
    public const TOKEN_PATTERN = '/^Expo(nent)?PushToken\[.+\]$/';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'push_token' => ['required', 'string', 'max:255', 'regex:'.self::TOKEN_PATTERN],
            'platform' => ['required', Rule::enum(DevicePlatform::class)],
            // Native installs must say which install they are: it is what ties
            // the device to the sign-in (logout cleanup) and to a web
            // subscription on the same handset (dedup).
            'install_id' => ['required_if:platform,ios,android', 'nullable', 'uuid'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^v?\d+(?:\.\d+){0,3}(?:[-+].*)?$/i'],
            'os_version' => ['sometimes', 'nullable', 'string', 'max:32'],
            'locale' => ['sometimes', 'nullable', 'string', 'max:16'],
            'timezone' => ['sometimes', 'nullable', 'string', 'timezone:all'],
        ];
    }
}
