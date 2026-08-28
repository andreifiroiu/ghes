<?php

declare(strict_types=1);

namespace App\Services\Recommendation;

use App\Models\Event;
use Illuminate\Support\Collection;

class DiversityFilter
{
    /**
     * Cap events per category and round-robin interleave for variety.
     *
     * @param  Collection<int, Event>  $events  Scored events, already sorted by relevance.
     * @param  int  $maxPerCategory  Maximum events from a single category.
     * @return Collection<int, Event>
     */
    public function filter(Collection $events, int $maxPerCategory = 3): Collection
    {
        $grouped = $events->groupBy(fn (Event $event) => $event->category->value);

        // Cap each group
        $capped = $grouped->map(fn (Collection $group) => $group->take($maxPerCategory)->values());

        // Round-robin interleave
        $result = collect();
        $maxSize = $capped->max(fn (Collection $g) => $g->count()) ?? 0;

        for ($i = 0; $i < $maxSize; $i++) {
            foreach ($capped as $group) {
                if (isset($group[$i])) {
                    $result->push($group[$i]);
                }
            }
        }

        return $result->values();
    }
}
