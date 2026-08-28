<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class AdminUserResource extends JsonResource
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
            'city' => $this->city,
            'onboarding_completed' => $this->onboarding_completed,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'notification_channel' => $this->notification_channel?->value,
            'notification_frequency' => $this->notification_frequency?->value,
            'discovery_openness' => $this->discovery_openness,
            'experiment_variant' => $this->experiment_variant,
            'reactions_count' => $this->whenCounted('reactions'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
