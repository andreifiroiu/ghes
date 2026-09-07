<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Event;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/**
 * A past recommendation batch — the digest that was sent, with the events it
 * carried resolved against the requesting user's current reaction state.
 *
 * @mixin Notification
 */
class RecommendationBatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        // Only ever rendered behind auth:sanctum. Without a user the events
        // would come back with a fabricated `is_saved: false` and no
        // `current_reaction` — a shape change, not an error — so fail loudly.
        if ($user === null) {
            throw new LogicException('RecommendationBatchResource needs an authenticated request.');
        }

        $eventIds = array_merge($this->event_ids ?? [], $this->discovery_event_ids ?? []);

        $events = Event::whereIn('id', $eventIds)->withUserContext($user)->get();

        return [
            'notification_id' => $this->id,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'discovery_event_ids' => $this->discovery_event_ids ?? [],
            'events' => EventResource::collection($events)->resolve(),
        ];
    }
}
