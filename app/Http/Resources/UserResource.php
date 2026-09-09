<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'interest_profile' => $this->interest_profile,
            'profile_summary' => $this->profile_summary,
            'profile_summary_updated_at' => $this->profile_summary_updated_at?->toIso8601String(),
            'discovery_openness' => $this->discovery_openness,
            'notification_channel' => $this->notification_channel?->value,
            'notification_frequency' => $this->notification_frequency?->value,
            'event_reminders_enabled' => $this->event_reminders_enabled,
            // Resolved, not raw: the column is null for anyone who has never
            // opened the setting, and a client showing "none selected" there
            // would be describing the opposite of what actually happens.
            'reminder_lead_minutes' => $this->reminderLeadMinutes(),
            'timezone' => $this->timezone,
            'city' => $this->city,
            'onboarding_completed' => $this->onboarding_completed,
        ];
    }
}
