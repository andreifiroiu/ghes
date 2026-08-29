<?php

declare(strict_types=1);

namespace App\Services\Recommendation;

use App\Enums\Reaction;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Finds events similar to a given event, for the "related events" strip on the
 * event detail page.
 *
 * This is event-to-event similarity, unlike RecommendationEngine, which scores
 * user-to-event. It is deliberately plain SQL plus an in-PHP score: the schema
 * carries no embeddings, and the Meilisearch index has no filterable attributes
 * configured, so a lexical search could not be constrained to upcoming, visible,
 * canonical events. Plain SQL also behaves identically on sqlite (tests) and
 * PostgreSQL (production).
 */
class RelatedEventFinder
{
    /**
     * Events similar to $event, most similar first.
     *
     * When $user is given, their reaction and bookmark state is eager-loaded so
     * EventResource can expose `current_reaction` and `is_saved` — without it
     * the strip's save and reaction buttons render as untouched.
     *
     * @return Collection<int, Event>
     */
    public function find(Event $event, ?User $user = null, ?int $limit = null): Collection
    {
        $limit ??= (int) config('eventpulse.recommendation.related.limit', 6);

        return $this->candidates($event, $user)
            ->map(fn (Event $candidate): array => [
                'event' => $candidate,
                'score' => $this->score($event, $candidate),
            ])
            // A candidate matched the query on a tag we have since stopped
            // counting, or on nothing that still scores; showing it would be
            // worse than showing a shorter strip.
            ->filter(fn (array $scored): bool => $scored['score'] > 0)
            ->sortBy([
                ['score', 'desc'],
                ['event.starts_at', 'asc'],
            ])
            ->take($limit)
            ->map(fn (array $scored): Event => $scored['event'])
            ->values();
    }

    /**
     * Upcoming, visible, canonical events sharing at least one coarse signal
     * with $event. Narrowing in SQL keeps the set that reaches the PHP scorer
     * small; the scorer is what actually ranks it.
     *
     * @return Collection<int, Event>
     */
    private function candidates(Event $event, ?User $user): Collection
    {
        $maxTags = (int) config('eventpulse.recommendation.related.max_tags_considered', 10);
        $tags = array_slice($event->tags ?? [], 0, $maxTags);

        return Event::query()
            ->upcoming()
            ->visible()
            ->canonical()
            ->whereKeyNot($event->getKey())
            ->where(function (Builder $query) use ($event, $tags): void {
                $query->where('category', $event->category);

                if ($event->venue !== null) {
                    $query->orWhere('venue', $event->venue);
                }

                foreach ($tags as $tag) {
                    $query->orWhereJsonContains('tags', $tag);
                }
            })
            ->when($user !== null, function (Builder $query) use ($user): void {
                /** @var User $user */
                $query->withUserContext($user);

                // Mirror the browse list: an event the user dismissed should not
                // come back at them from a related strip.
                $query->whereNotIn('id', $user->reactions()
                    ->where('reaction', Reaction::NotInterested)
                    ->pluck('event_id'));
            })
            // Order before limiting, or the candidate slice is arbitrary and the
            // same event yields different related strips between requests.
            // `starts_at` alone is not a total order — event times cluster hard
            // on round hours, so once a category exceeds the candidate limit the
            // rows tied at the cut would vary with the query plan. `id` breaks
            // the tie.
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit((int) config('eventpulse.recommendation.related.candidate_limit', 100))
            ->get();
    }

    /**
     * How related $candidate is to $event, in points. Higher is more related.
     */
    private function score(Event $event, Event $candidate): int
    {
        // Defaulted like every other read in this class. Without it, a config
        // cache written before this key existed makes `$points` null and the
        // whole detail page — web and API — 500s on an array offset, with
        // nothing in the trace naming the missing key.
        /** @var array{category: int, tag: int, tag_cap: int, venue: int, city: int, date_proximity: int} $points */
        $points = (array) config('eventpulse.recommendation.related.points', []) + [
            'category' => 3,
            'tag' => 2,
            'tag_cap' => 6,
            'venue' => 2,
            'city' => 1,
            'date_proximity' => 1,
        ];

        $score = 0;

        if ($candidate->category === $event->category) {
            $score += $points['category'];
        }

        $sharedTags = count(array_intersect($event->tags ?? [], $candidate->tags ?? []));
        $score += min($sharedTags * $points['tag'], $points['tag_cap']);

        if ($event->venue !== null && $candidate->venue === $event->venue) {
            $score += $points['venue'];
        }

        if ($event->city !== null && $candidate->city === $event->city) {
            $score += $points['city'];
        }

        if ($this->startsNearby($event, $candidate)) {
            $score += $points['date_proximity'];
        }

        return $score;
    }

    /**
     * Whether the two events start close enough together that someone planning
     * around one could plausibly attend the other.
     */
    private function startsNearby(Event $event, Event $candidate): bool
    {
        if ($event->starts_at === null || $candidate->starts_at === null) {
            return false;
        }

        $days = (int) config('eventpulse.recommendation.related.date_proximity_days', 14);

        return abs($event->starts_at->diffInDays($candidate->starts_at)) <= $days;
    }
}
