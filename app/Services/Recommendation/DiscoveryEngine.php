<?php

declare(strict_types=1);

namespace App\Services\Recommendation;

use App\Enums\EventCategory;
use App\Enums\Reaction;
use App\Models\DiscoveryLog;
use App\Models\Event;
use App\Models\User;
use App\Models\UserEventReaction;
use Illuminate\Support\Collection;

class DiscoveryEngine
{
    /**
     * Select discovery events for a user.
     *
     * Reserves a slot for platform-wide trending events (high engagement,
     * regardless of profile), then fills the rest from categories the user
     * rarely engages with — skipping categories under serendipity suppression
     * and events carrying the user's negative tags.
     *
     * @return Collection<int, Event>
     */
    public function discoverForUser(User $user, int $count = 2): Collection
    {
        if ($count < 1) {
            return collect();
        }

        /** @var list<string> $reactedEventIds */
        $reactedEventIds = $user->reactions()->pluck('event_id')->all();
        $negativeTags = $user->negativeTags();

        $trending = $this->trendingEvents($user, $reactedEventIds, $negativeTags, $count);

        $excludeIds = array_merge($reactedEventIds, $trending->pluck('id')->all());
        $remaining = $count - $trending->count();

        $categoryEvents = $remaining > 0
            ? $this->categoryDiscovery($user, $excludeIds, $negativeTags, $remaining)
            : collect();

        /** @var Collection<int, Event> $events */
        $events = $trending->concat($categoryEvents)->take($count)->values();

        // Log each surfaced discovery once per (user, event) for analytics,
        // suppression, and hit-rate tuning.
        $events->each(function (Event $event) use ($user): void {
            DiscoveryLog::firstOrCreate(
                ['user_id' => $user->id, 'event_id' => $event->id],
                [
                    'category_explored' => $event->category->value,
                    'surprise_score' => $this->calculateSurpriseScore($user, $event),
                ],
            );
        });

        return $events;
    }

    /**
     * Platform-wide trending events: those with the most positive reactions
     * within the trending window, surfaced regardless of the user's profile.
     *
     * @param  list<string>  $reactedEventIds
     * @param  list<string>  $negativeTags
     * @return Collection<int, Event>
     */
    private function trendingEvents(User $user, array $reactedEventIds, array $negativeTags, int $count): Collection
    {
        $slots = min((int) config('eventpulse.discovery.trending_slots', 1), $count);

        if ($slots < 1) {
            return collect();
        }

        $minReactions = (int) config('eventpulse.discovery.trending_min_reactions', 3);
        $windowDays = (int) config('eventpulse.discovery.trending_window_days', 14);
        $positive = [Reaction::Interested->value, Reaction::Saved->value];

        $trendingCounts = UserEventReaction::query()
            ->whereIn('reaction', $positive)
            ->where('created_at', '>=', now()->subDays($windowDays))
            ->whereNotIn('event_id', $reactedEventIds)
            ->pluck('event_id')
            ->countBy()
            ->filter(fn (int $reactionCount) => $reactionCount >= $minReactions)
            ->sortDesc();

        if ($trendingCounts->isEmpty()) {
            return collect();
        }

        $events = Event::upcoming()
            ->visible()
            ->where('is_classified', true)
            ->whereIn('id', $trendingCounts->keys()->all())
            ->when($user->city, fn ($query) => $query->where('city', $user->city))
            ->get();

        return $this->rejectNegativeTags($events, $negativeTags)
            ->sortByDesc(fn (Event $event) => $trendingCounts[$event->id] ?? 0)
            ->take($slots)
            ->values();
    }

    /**
     * Discovery from low-interest categories, excluding suppressed categories.
     *
     * @param  list<string>  $excludeIds
     * @param  list<string>  $negativeTags
     * @return Collection<int, Event>
     */
    private function categoryDiscovery(User $user, array $excludeIds, array $negativeTags, int $count): Collection
    {
        $profile = $user->interest_profile ?? [];
        $minSurprise = (float) config('eventpulse.discovery.min_surprise_score', 0.3);
        $suppressed = $this->suppressedCategories($user);

        $lowScoreCategories = collect(EventCategory::cases())
            ->map(fn (EventCategory $cat) => $cat->value)
            ->filter(fn (string $cat) => (1.0 - (float) ($profile[$cat] ?? 0.0)) >= $minSurprise)
            ->reject(fn (string $cat) => in_array($cat, $suppressed, true))
            ->values()
            ->all();

        if ($lowScoreCategories === []) {
            return collect();
        }

        // Bias toward low-score categories that similar users react to positively.
        $preferred = array_values(array_intersect(
            $this->collaborativelyPopularCategories($user),
            $lowScoreCategories,
        ));

        $events = $this->fetchDiscoveryEvents($preferred, $excludeIds, $negativeTags, $count, $user->city);

        if ($events->count() < $count) {
            $usedIds = array_merge($excludeIds, $events->pluck('id')->all());
            $events = $events->concat(
                $this->fetchDiscoveryEvents($lowScoreCategories, $usedIds, $negativeTags, $count - $events->count(), $user->city),
            );
        }

        return $events->take($count)->values();
    }

    /**
     * Fetch upcoming, classified discovery events in the given categories,
     * excluding already-used events and those carrying negative tags, scoped to
     * the user's city when set.
     *
     * @param  list<string>  $categories
     * @param  list<string>  $excludeIds
     * @param  list<string>  $negativeTags
     * @return Collection<int, Event>
     */
    private function fetchDiscoveryEvents(array $categories, array $excludeIds, array $negativeTags, int $count, ?string $city = null): Collection
    {
        if ($categories === [] || $count < 1) {
            return collect();
        }

        $events = Event::upcoming()
            ->visible()
            ->whereIn('category', $categories)
            ->whereNotIn('id', $excludeIds)
            ->where('is_classified', true)
            ->when($city, fn ($query) => $query->where('city', $city))
            ->inRandomOrder()
            ->limit($negativeTags === [] ? $count : $count * 5)
            ->get();

        return $this->rejectNegativeTags($events, $negativeTags)->take($count)->values();
    }

    /**
     * Categories popular among users similar to this one — ordered by popularity.
     * Used to bias discovery (collaborative filtering, SPEC §3.4).
     *
     * "Similar users" are those who positively reacted to events in the current
     * user's high-interest categories; the result is the categories *they* react
     * to positively. Behaviour-based and portable (no JSON column queries).
     *
     * @return list<string>
     */
    public function collaborativelyPopularCategories(User $user): array
    {
        $profile = $user->interest_profile ?? [];
        $threshold = (float) config('eventpulse.discovery.similar_user_threshold', 0.6);
        $positive = [Reaction::Interested->value, Reaction::Saved->value];

        $highCategories = collect(EventCategory::cases())
            ->map(fn (EventCategory $cat) => $cat->value)
            ->filter(fn (string $cat) => (float) ($profile[$cat] ?? 0.0) >= $threshold)
            ->all();

        if ($highCategories === []) {
            return [];
        }

        // Kept as subqueries: the events table grows with every scrape, so
        // plucking its ids into PHP would build an unbounded IN list, and the
        // similar-user limit has to be applied by the database, not after.
        $similarUserIds = UserEventReaction::query()
            ->whereIn('reaction', $positive)
            ->where('user_id', '!=', $user->id)
            ->whereIn('event_id', Event::query()
                ->whereIn('category', $highCategories)
                ->select('id'))
            ->distinct()
            ->limit((int) config('eventpulse.discovery.similar_user_limit', 200))
            ->pluck('user_id');

        if ($similarUserIds->isEmpty()) {
            return [];
        }

        return Event::query()
            ->whereIn('id', UserEventReaction::query()
                ->whereIn('user_id', $similarUserIds)
                ->whereIn('reaction', $positive)
                ->select('event_id'))
            ->get(['category'])
            ->countBy(fn (Event $event) => (string) $event->getRawOriginal('category'))
            ->sortDesc()
            ->keys()
            ->all();
    }

    /**
     * Categories under serendipity suppression: surfaced at least the threshold
     * number of times within the suppression window with zero positive outcomes.
     *
     * @return list<string>
     */
    public function suppressedCategories(User $user): array
    {
        $threshold = (int) config('eventpulse.discovery.suppression_threshold', 3);
        $days = (int) config('eventpulse.discovery.suppression_days', 30);
        $positive = [Reaction::Interested->value, Reaction::Saved->value];

        return DiscoveryLog::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subDays($days))
            ->get(['category_explored', 'outcome'])
            ->groupBy('category_explored')
            ->filter(function (Collection $logs) use ($threshold, $positive) {
                $positives = $logs->whereIn('outcome', $positive)->count();

                return $logs->count() >= $threshold && $positives === 0;
            })
            ->keys()
            ->all();
    }

    /**
     * Lower a user's discovery_openness when their discovery hit rate is poor.
     *
     * Once a user has resolved enough discovery events, if the share that got a
     * positive reaction falls below the threshold, nudge openness down (never
     * below the configured floor). Openness is never raised here.
     */
    public function recalibrateOpenness(User $user): void
    {
        $minSamples = (int) config('eventpulse.discovery.openness_min_samples', 5);
        $threshold = (float) config('eventpulse.discovery.openness_hit_rate_threshold', 0.1);
        $step = (float) config('eventpulse.discovery.openness_step', 0.05);
        $floor = (float) config('eventpulse.discovery.openness_floor', 0.05);
        $positive = [Reaction::Interested->value, Reaction::Saved->value];

        $resolved = DiscoveryLog::query()
            ->where('user_id', $user->id)
            ->whereNotNull('outcome')
            ->get(['outcome']);

        if ($resolved->count() < $minSamples) {
            return;
        }

        $hitRate = $resolved->whereIn('outcome', $positive)->count() / $resolved->count();

        if ($hitRate >= $threshold) {
            return;
        }

        $current = (float) $user->discovery_openness;
        $new = max($floor, $current - $step);

        if ($new !== $current) {
            $user->update(['discovery_openness' => $new]);
        }
    }

    /**
     * Surprise = 1 − user's profile score for the event's category.
     */
    public function calculateSurpriseScore(User $user, Event $event): float
    {
        $profile = $user->interest_profile ?? [];
        $categoryScore = (float) ($profile[$event->category->value] ?? 0.0);

        return max(0.0, min(1.0, 1.0 - $categoryScore));
    }

    /**
     * Drop events carrying any of the user's negative tags.
     *
     * @param  Collection<int, Event>  $events
     * @param  list<string>  $negativeTags
     * @return Collection<int, Event>
     */
    private function rejectNegativeTags(Collection $events, array $negativeTags): Collection
    {
        if ($negativeTags === []) {
            return $events;
        }

        return $events->reject(
            fn (Event $event) => array_intersect($event->tags ?? [], $negativeTags) !== [],
        )->values();
    }
}
