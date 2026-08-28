<?php

declare(strict_types=1);

namespace App\Services\Recommendation;

use App\Enums\EventCategory;
use App\Models\DiscoveryLog;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Collection;

class DiscoveryEngine
{
    /**
     * Select discovery events from categories the user rarely engages with.
     *
     * @return Collection<int, Event>
     */
    public function discoverForUser(User $user, int $count = 2): Collection
    {
        $profile = $user->interest_profile ?? [];
        $minSurprise = (float) config('eventpulse.discovery.min_surprise_score', 0.3);

        // Categories whose surprise score (1 − user score) ≥ threshold
        $lowScoreCategories = collect(EventCategory::cases())
            ->filter(fn (EventCategory $cat) => (1.0 - ($profile[$cat->value] ?? 0.0)) >= $minSurprise)
            ->map(fn (EventCategory $cat) => $cat->value)
            ->values()
            ->toArray();

        if ($lowScoreCategories === []) {
            return collect();
        }

        $reactedEventIds = $user->reactions()->pluck('event_id');

        $events = Event::canonical()->upcoming()
            ->whereIn('category', $lowScoreCategories)
            ->whereNotIn('id', $reactedEventIds)
            ->where('is_classified', true)
            ->inRandomOrder()
            ->limit($count)
            ->get();

        // Log each discovery for analytics
        $events->each(function (Event $event) use ($user): void {
            DiscoveryLog::create([
                'user_id' => $user->id,
                'event_id' => $event->id,
                'category_explored' => $event->category->value,
                'surprise_score' => $this->calculateSurpriseScore($user, $event),
            ]);
        });

        return $events;
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
