<?php

declare(strict_types=1);

namespace App\Services\Recommendation;

use App\Enums\EventCategory;
use App\Enums\Reaction;
use App\Models\DiscoveryLog;
use App\Models\Event;
use App\Models\EventBookmark;
use App\Models\User;
use App\Models\UserEventReaction;
use App\Services\Processing\EventTextNormalizer;
use Illuminate\Support\Collection;

class DiscoveryEngine
{
    /**
     * Select discovery events for a user.
     *
     * Reserves a slot for platform-wide trending events (high engagement,
     * regardless of profile), then fills the rest from categories the user
     * rarely engages with — skipping categories under serendipity suppression
     * and anything the user has already reacted to or bookmarked.
     *
     * @param  list<string>  $excludeEventIds  Events already used elsewhere in the batch.
     * @return Collection<int, Event>
     */
    public function discoverForUser(User $user, int $count = 2, array $excludeEventIds = []): Collection
    {
        if ($count < 1) {
            return collect();
        }

        /** @var list<string> $engagedEventIds */
        $engagedEventIds = $user->reactions()->pluck('event_id')
            ->merge($user->bookmarks()->pluck('event_id'))
            ->unique()
            ->values()
            ->all();

        // Preferred pass: also avoid anything already recommended in the same
        // batch, so the dashboard never shows one event as both a
        // recommendation and a discovery.
        $events = $this->gather(
            $user,
            $count,
            array_values(array_unique(array_merge($engagedEventIds, $excludeEventIds))),
        );

        // But that exclusion must never be allowed to empty the slot. In a thin
        // catalogue — the exact situation a small city is in — the diversity
        // filter pulls the off-profile events into the recommendations, leaving
        // discovery nothing to draw from, and both the dashboard section and
        // the digest's exploration block silently disappear. A repeated card is
        // the lesser failure, so top up without the batch exclusion.
        if ($events->count() < $count && $excludeEventIds !== []) {
            $topUp = $this->gather(
                $user,
                $count - $events->count(),
                array_merge($engagedEventIds, $events->pluck('id')->all()),
            );

            /** @var Collection<int, Event> $events */
            $events = $events->concat($topUp)->take($count)->values();
        }

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
     * One discovery pass: trending first, then low-interest categories.
     *
     * @param  list<string>  $excludeIds
     * @return Collection<int, Event>
     */
    private function gather(User $user, int $count, array $excludeIds): Collection
    {
        if ($count < 1) {
            return collect();
        }

        $trending = $this->trendingEvents($user, $excludeIds, $count);

        $remaining = $count - $trending->count();

        $categoryEvents = $remaining > 0
            ? $this->categoryDiscovery(
                $user,
                array_merge($excludeIds, $trending->pluck('id')->all()),
                $remaining,
            )
            : collect();

        /** @var Collection<int, Event> $gathered */
        $gathered = $trending->concat($categoryEvents)->take($count)->values();

        return $gathered;
    }

    /**
     * Platform-wide trending events: those with the most positive reactions
     * within the trending window, surfaced regardless of the user's profile.
     *
     * @param  list<string>  $engagedEventIds
     * @return Collection<int, Event>
     */
    private function trendingEvents(User $user, array $engagedEventIds, int $count): Collection
    {
        $slots = min((int) config('eventpulse.discovery.trending_slots', 1), $count);

        if ($slots < 1) {
            return collect();
        }

        $minReactions = (int) config('eventpulse.discovery.trending_min_reactions', 3);
        $windowDays = (int) config('eventpulse.discovery.trending_window_days', 14);
        $since = now()->subDays($windowDays);

        // A bookmark counts as a positive signal alongside an "interested"
        // reaction, so trending has to read both tables — but it measures how
        // many *people* engaged, so one user who both reacts and saves must
        // still count once. Deduplicate by (user, event) before counting, or
        // two enthusiasts clear a threshold documented as needing three.
        // toBase() is required: mapping an *empty* Eloquent collection returns
        // an Eloquent collection, whose merge() would then call getKey() on
        // these plain arrays.
        $engagements = UserEventReaction::query()
            ->where('reaction', Reaction::Interested->value)
            ->where('created_at', '>=', $since)
            ->whereNotIn('event_id', $engagedEventIds)
            ->get(['user_id', 'event_id'])
            ->toBase()
            ->map(fn ($row): array => ['user_id' => $row->user_id, 'event_id' => $row->event_id])
            ->merge(
                EventBookmark::query()
                    ->where('created_at', '>=', $since)
                    ->whereNotIn('event_id', $engagedEventIds)
                    ->get(['user_id', 'event_id'])
                    ->toBase()
                    ->map(fn ($row): array => ['user_id' => $row->user_id, 'event_id' => $row->event_id]),
            );

        $trendingCounts = $engagements
            ->unique(fn (array $row): string => $row['user_id'].'|'.$row['event_id'])
            ->countBy('event_id')
            ->filter(fn (int $engagedUsers) => $engagedUsers >= $minReactions)
            ->sortDesc();

        if ($trendingCounts->isEmpty()) {
            return collect();
        }

        $citySlug = EventTextNormalizer::citySlug($user->city);

        $events = Event::upcoming()
            ->visible()
            ->canonical()
            ->where('is_classified', true)
            ->whereIn('id', $trendingCounts->keys()->all())
            ->when(
                $citySlug !== null,
                fn ($query) => $query->where('city_slug', $citySlug),
            )
            ->get();

        return $events
            ->sortByDesc(fn (Event $event) => $trendingCounts[$event->id] ?? 0)
            ->take($slots)
            ->values();
    }

    /**
     * Discovery from low-interest categories, excluding suppressed categories.
     *
     * @param  list<string>  $excludeIds
     * @return Collection<int, Event>
     */
    private function categoryDiscovery(User $user, array $excludeIds, int $count): Collection
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

        $citySlug = EventTextNormalizer::citySlug($user->city);

        $events = $this->fetchDiscoveryEvents($preferred, $excludeIds, $count, $citySlug);

        if ($events->count() < $count) {
            $usedIds = array_merge($excludeIds, $events->pluck('id')->all());
            $events = $events->concat(
                $this->fetchDiscoveryEvents($lowScoreCategories, $usedIds, $count - $events->count(), $citySlug),
            );
        }

        return $events->take($count)->values();
    }

    /**
     * Fetch upcoming, classified discovery events in the given categories,
     * excluding already-used events, scoped to the user's city when set.
     *
     * @param  list<string>  $categories
     * @param  list<string>  $excludeIds
     * @param  string|null  $citySlug  Normalised city slug, not the raw label.
     * @return Collection<int, Event>
     */
    private function fetchDiscoveryEvents(array $categories, array $excludeIds, int $count, ?string $citySlug = null): Collection
    {
        if ($categories === [] || $count < 1) {
            return collect();
        }

        $events = Event::upcoming()
            ->visible()
            ->canonical()
            ->whereIn('category', $categories)
            ->whereNotIn('id', $excludeIds)
            ->where('is_classified', true)
            ->when($citySlug !== null, fn ($query) => $query->where('city_slug', $citySlug))
            ->inRandomOrder()
            ->limit($count)
            ->get();

        return $events->take($count)->values();
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

        $highCategories = collect(EventCategory::cases())
            ->map(fn (EventCategory $cat) => $cat->value)
            ->filter(fn (string $cat) => (float) ($profile[$cat] ?? 0.0) >= $threshold)
            ->all();

        if ($highCategories === []) {
            return [];
        }

        $limit = (int) config('eventpulse.discovery.similar_user_limit', 200);

        // Kept as subqueries: the events table grows with every scrape, so
        // plucking its ids into PHP would build an unbounded IN list, and the
        // per-table limit has to be applied by the database, not after.
        // Reactions and bookmarks are queried separately and merged, so the
        // result is bounded by 2 * $limit rather than being unbounded.
        $similarUserIds = UserEventReaction::query()
            ->where('reaction', Reaction::Interested->value)
            ->where('user_id', '!=', $user->id)
            ->whereIn('event_id', Event::query()
                ->whereIn('category', $highCategories)
                ->select('id'))
            ->distinct()
            ->limit($limit)
            ->pluck('user_id')
            ->merge(
                EventBookmark::query()
                    ->where('user_id', '!=', $user->id)
                    ->whereIn('event_id', Event::query()
                        ->whereIn('category', $highCategories)
                        ->select('id'))
                    ->distinct()
                    ->limit($limit)
                    ->pluck('user_id'),
            )
            ->unique()
            ->values();

        if ($similarUserIds->isEmpty()) {
            return [];
        }

        return Event::query()
            ->where(function ($query) use ($similarUserIds): void {
                $query->whereIn('id', UserEventReaction::query()
                    ->whereIn('user_id', $similarUserIds)
                    ->where('reaction', Reaction::Interested->value)
                    ->select('event_id'))
                    ->orWhereIn('id', EventBookmark::query()
                        ->whereIn('user_id', $similarUserIds)
                        ->select('event_id'));
            })
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

        return DiscoveryLog::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subDays($days))
            ->get(['category_explored', 'outcome'])
            ->groupBy('category_explored')
            ->filter(function (Collection $logs) use ($threshold) {
                $positives = $logs->whereIn('outcome', DiscoveryLog::POSITIVE_OUTCOMES)->count();

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

        $resolved = DiscoveryLog::query()
            ->where('user_id', $user->id)
            ->whereNotNull('outcome')
            ->get(['outcome']);

        if ($resolved->count() < $minSamples) {
            return;
        }

        $hitRate = $resolved->whereIn('outcome', DiscoveryLog::POSITIVE_OUTCOMES)->count() / $resolved->count();

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
}
