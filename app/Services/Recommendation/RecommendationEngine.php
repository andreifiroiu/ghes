<?php

declare(strict_types=1);

namespace App\Services\Recommendation;

use App\DTOs\RecommendationBatch;
use App\Models\Event;
use App\Models\User;
use App\Services\InterestProfile\ProfileScorer;
use DateTimeImmutable;

class RecommendationEngine
{
    public function __construct(
        private readonly ProfileScorer $profileScorer,
        private readonly DiscoveryEngine $discoveryEngine,
        private readonly DiversityFilter $diversityFilter,
    ) {}

    /**
     * Score a single event for a user using a weighted multi-factor formula.
     *
     * Factors: category match, tag overlap, location proximity, time proximity,
     * price fit, freshness since scrape, and popularity signal.
     *
     * @return float Score between 0.0 and 1.0
     */
    public function scoreEvent(User $user, Event $event): float
    {
        /** @var array{category: float, tags: float, location: float, time: float, price: float, freshness: float, popularity: float} $weights */
        $weights = config('eventpulse.recommendation.weights');

        $categoryScore = $this->categoryMatch($user, $event);
        $tagScore = $this->tagMatch($user, $event);
        $locationScore = $this->locationMatch($user, $event);
        $timeScore = $this->timeMatch($event);
        $priceScore = $this->priceMatch($event);
        $freshnessScore = $this->freshnessBonus($event);
        $popularityScore = $this->popularitySignal($event);

        $score = ($weights['category'] * $categoryScore)
            + ($weights['tags'] * $tagScore)
            + ($weights['location'] * $locationScore)
            + ($weights['time'] * $timeScore)
            + ($weights['price'] * $priceScore)
            + ($weights['freshness'] * $freshnessScore)
            + ($weights['popularity'] * $popularityScore);

        return max(0.0, min(1.0, $score));
    }

    /**
     * Generate a recommendation batch for a user.
     *
     * 1. Fetch upcoming, classified events the user hasn't reacted to
     * 2. Score and sort each candidate
     * 3. Apply diversity filter
     * 4. Reserve discovery slots from DiscoveryEngine
     * 5. Assemble RecommendationBatch DTO
     */
    public function recommend(User $user, int $limit = 8): RecommendationBatch
    {
        $reactedEventIds = $user->reactions()->pluck('event_id');

        $candidates = Event::upcoming()
            ->where('is_classified', true)
            ->when($user->city, fn ($q) => $q->where('city', $user->city))
            ->whereNotIn('id', $reactedEventIds)
            ->limit(200)
            ->get();

        // Hard-filter events carrying tags the user has suppressed.
        $negativeTags = $user->negativeTags();
        if ($negativeTags !== []) {
            $candidates = $candidates->reject(
                fn (Event $event) => array_intersect($event->tags ?? [], $negativeTags) !== [],
            )->values();
        }

        // Score every candidate
        $scored = $candidates
            ->map(fn (Event $event) => ['event' => $event, 'score' => $this->scoreEvent($user, $event)])
            ->sortByDesc('score')
            ->values();

        // Discovery budget
        $explorationBudget = (float) config('eventpulse.discovery.exploration_budget', 0.2);
        $discoveryCount = max(1, (int) round($limit * $explorationBudget));
        $recommendationCount = $limit - $discoveryCount;

        // Apply diversity filter, then take top N
        $diverseEvents = $this->diversityFilter->filter(
            $scored->pluck('event'),
        );
        $recommended = $diverseEvents->take($recommendationCount);

        // Discovery events
        $discoveryEvents = $this->discoveryEngine->discoverForUser($user, $discoveryCount);

        // Average score of recommended set
        $recommendedIds = $recommended->pluck('id')->toArray();
        $totalScore = $scored
            ->whereIn('event.id', $recommendedIds)
            ->avg('score') ?? 0.0;

        return new RecommendationBatch(
            userId: $user->id,
            recommendedEventIds: $recommendedIds,
            discoveryEventIds: $discoveryEvents->pluck('id')->toArray(),
            totalScore: (float) $totalScore,
            generatedAt: new DateTimeImmutable,
        );
    }

    // ------------------------------------------------------------------
    // Individual scoring functions
    // ------------------------------------------------------------------

    /**
     * How well the event's category matches the user's interests.
     * Returns the user's profile score for the event's category (0–1).
     */
    public function categoryMatch(User $user, Event $event): float
    {
        return $this->profileScorer->calculateCategoryScore(
            $user->interest_profile ?? [],
            $event->category->value,
        );
    }

    /**
     * Average profile score across the event's tags.
     * 0.0 when there are no tags or none match.
     */
    public function tagMatch(User $user, Event $event): float
    {
        return $this->profileScorer->calculateTagScore(
            $user->interest_profile ?? [],
            $event->tags ?? [],
        );
    }

    /**
     * 1.0 when user and event share the same city, 0.3 otherwise.
     * If the user has no city set, default to 0.5 (neutral).
     */
    public function locationMatch(User $user, Event $event): float
    {
        if (! $user->city) {
            return 0.5;
        }

        return mb_strtolower($user->city) === mb_strtolower((string) $event->city)
            ? 1.0
            : 0.3;
    }

    /**
     * Time proximity: events 1–7 days out score highest, with exponential
     * decay after that. Events in the past or with no date score 0.
     */
    public function timeMatch(Event $event): float
    {
        if (! $event->starts_at) {
            return 0.0;
        }

        $daysUntil = now()->diffInDays($event->starts_at);

        if ($event->starts_at->isPast()) {
            return 0.0;
        }

        $days = (int) abs($daysUntil);

        // Peak at 1–3 days, gentle decay after
        return max(0.0, min(1.0, exp(-0.08 * max(0, $days - 1))));
    }

    /**
     * Free events score 1.0. Paid events decay linearly up to a 200-unit
     * price ceiling, flooring at 0.2 so paid events are never entirely excluded.
     */
    public function priceMatch(Event $event): float
    {
        if ($event->is_free) {
            return 1.0;
        }

        $price = $event->price_min ?? 0.0;

        return max(0.2, 1.0 - ($price / 200.0));
    }

    /**
     * Freshness bonus: exponential decay from the time the event was scraped.
     * An event scraped today scores 1.0; one scraped 30 days ago scores ~0.22.
     */
    public function freshnessBonus(Event $event): float
    {
        if (! $event->created_at) {
            return 0.5;
        }

        $daysSince = (int) abs(now()->diffInDays($event->created_at));

        return max(0.0, min(1.0, exp(-0.05 * $daysSince)));
    }

    /**
     * Normalised popularity: event popularity_score mapped to 0–1,
     * assuming a practical ceiling of 100.
     */
    public function popularitySignal(Event $event): float
    {
        $score = $event->popularity_score ?? 0;

        return max(0.0, min(1.0, $score / 100.0));
    }
}
