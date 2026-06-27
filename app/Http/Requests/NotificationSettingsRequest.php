<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\NotificationChannel;
use App\Enums\NotificationFrequency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NotificationSettingsRequest extends FormRequest
{
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
            'channel' => ['required', 'string', Rule::in(array_column(NotificationChannel::cases(), 'value'))],
            'frequency' => ['required', 'string', Rule::in(array_column(NotificationFrequency::cases(), 'value'))],
            'discovery_openness' => ['sometimes', 'numeric', 'min:0', 'max:1'],
        ];
    }
}
