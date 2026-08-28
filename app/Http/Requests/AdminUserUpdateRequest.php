<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\NotificationChannel;
use App\Enums\NotificationFrequency;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminUserUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('access-admin') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var User $target */
        $target = $this->route('user');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($target->id)],
            'city' => ['nullable', 'string', 'max:255'],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'notification_channel' => ['sometimes', Rule::in(array_column(NotificationChannel::cases(), 'value'))],
            'notification_frequency' => ['sometimes', Rule::in(array_column(NotificationFrequency::cases(), 'value'))],
        ];
    }
}
