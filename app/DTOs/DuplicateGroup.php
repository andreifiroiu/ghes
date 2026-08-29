<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\Event;
use Illuminate\Support\Collection;

/**
 * A cluster of canonical events that look like the same real-world event.
 *
 * The first member is the one that would make the best canonical row; the
 * rest are the duplicates that would be folded into it.
 */
final readonly class DuplicateGroup
{
    /**
     * @param  Collection<int, Event>  $events  Ranked best-canonical-first.
     * @param  'match_key'|'score'  $reason  Which pass surfaced the cluster.
     * @param  float  $score  Confidence in [0, 1]; always 1.0 for a key match.
     */
    public function __construct(
        public string $key,
        public Collection $events,
        public string $reason,
        public float $score,
    ) {}

    public function canonical(): Event
    {
        return $this->events->first();
    }

    /**
     * @return Collection<int, Event>
     */
    public function duplicates(): Collection
    {
        return $this->events->slice(1)->values();
    }
}
