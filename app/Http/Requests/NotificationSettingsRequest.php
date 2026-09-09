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
        /** @var list<int> $leadOptions */
        $leadOptions = array_map(intval(...), (array) config('eventpulse.reminders.lead_options', []));

        return [
            'channel' => ['required', 'string', Rule::in(array_column(NotificationChannel::cases(), 'value'))],
            'frequency' => ['required', 'string', Rule::in(array_column(NotificationFrequency::cases(), 'value'))],
            'discovery_openness' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            // `sometimes` throughout: the profile page posts only what it owns,
            // and a page that omits a key must never be read as switching it
            // off. Sending an empty array is a real "no lead times", which is
            // why the toggle is a separate flag rather than an empty list.
            'event_reminders' => ['sometimes', 'boolean'],
            'reminder_lead_minutes' => ['sometimes', 'array'],
            'reminder_lead_minutes.*' => ['integer', Rule::in($leadOptions)],
        ];
    }
}
