<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Event
 */
class EventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category->value,
            'tags' => $this->tags,
            'venue' => $this->venue,
            'address' => $this->address,
            'city' => $this->city,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'price_min' => $this->price_min,
            'price_max' => $this->price_max,
            'is_free' => $this->is_free,
            'image_url' => $this->image_url,
            'popularity_score' => $this->popularity_score,
            'source' => $this->source,
            'source_url' => $this->source_url,
            // What the UI should actually link to. Goes through our redirect so
            // the click is recorded, then lands on source_url. Kept alongside
            // source_url rather than replacing it so API consumers that only
            // want the destination still have it.
            'click_url' => route('events.go', ['event' => $this->id]),
            'sources_count' => $this->sources_count,
            'sources' => $this->whenLoaded('sources', fn () => $this->sources
                ->map(fn ($source): array => [
                    'source' => $source->source,
                    'source_url' => $source->source_url,
                ])->values()),
            'current_reaction' => $this->whenLoaded(
                'reactions',
                fn () => $this->reactions->first()?->reaction?->value,
            ),
            'is_saved' => $this->whenLoaded(
                'bookmarks',
                fn (): bool => $this->bookmarks->isNotEmpty(),
                false,
            ),
        ];
    }
}
