<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Event
 */
class AdminEventResource extends JsonResource
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
            'neighborhood' => $this->neighborhood,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'price_min' => $this->price_min,
            'price_max' => $this->price_max,
            'is_free' => $this->is_free,
            'currency' => $this->currency,
            'image_url' => $this->image_url,
            'source' => $this->source,
            'sources_count' => $this->sources_count,
            'merged_into_id' => $this->merged_into_id,
            'source_url' => $this->source_url,
            'popularity_score' => $this->popularity_score,
            'is_classified' => $this->is_classified,
            'is_geocoded' => $this->is_geocoded,
            'is_enriched' => $this->is_enriched,
            'is_hidden' => $this->is_hidden,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
