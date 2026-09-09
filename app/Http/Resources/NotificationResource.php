<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Notification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // History holds digests and reminders. A client that shows them the
            // same way would label "Diseară: X" as a recommendation batch, so
            // the discriminator travels with the row rather than being inferred
            // from whether event_ids happens to hold exactly one id.
            'type' => $this->type->value,
            'event_id' => $this->event_id,
            'lead_minutes' => $this->lead_minutes,
            'channel' => $this->channel->value,
            'frequency' => $this->frequency->value,
            'subject' => $this->subject,
            'event_ids' => $this->event_ids ?? [],
            'discovery_event_ids' => $this->discovery_event_ids ?? [],
            'sent_at' => $this->sent_at?->toIso8601String(),
            'opened_at' => $this->opened_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
