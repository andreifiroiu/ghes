<?php

declare(strict_types=1);

namespace App\Services\Recommendation;

use App\DTOs\RecommendationBatch;
use App\Models\Event;
use App\Models\User;
use App\Services\InterestProfile\ProfileScorer;
use App\Services\Processing\EventTextNormalizer;
use DateTimeImmutable;

class RecommendationEngine
{
    public function __construct(
        private readonly ProfileScorer $profileScorer,
        private readonly DiscoveryEngine $discoveryEngine,
        private readonly DiversityFilter $diversityFilter,
        private readonly ExperimentAssigner $experimentAssigner,
    ) {}

    /**
     * Score a single event for a user using a weighted multi-factor formula.
     *
     * Factors: category match, tag overlap, source affinity, location proximity,
     * time proximity, price fit, freshness since scrape, and popularity signal.
     *
     * @return float Score between 0.0 and 1.0
     */
    public function scoreEvent(User $user, Event $event): float
    {
        /** @var array{category: float, tags: float, location: float, time: float, price: float, freshness: float, popularity: float, source?: float} $weights */
        $weights = $this->experimentAssigner->weightsFor($user);

        $categoryScore = $this->categoryMatch($user, $event);
        $tagScore = $this->tagMatch($user, $event);
        $sourceScore = $this->sourceMatch($user, $event);
        $locationScore = $this->locationMatch($user, $event);
        $timeScore = $this->timeMatch($event);
        $priceScore = $this->priceMatch($event);
        $freshnessScore = $this->freshnessBonus($event);
        $popularityScore = $this->popularitySignal($event);

        $score = ($weights['category'] * $categoryScore)
            + ($weights['tags'] * $tagScore)
            + (($weights['source'] ?? 0.0) * $sourceScore)
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
        // Anything the user has already engaged with — reacted to or bookmarked
        // — is not a candidate for re-recommendation.
        $engagedEventIds = $user->reactions()->pluck('event_id')
            ->merge($user->bookmarks()->pluck('event_id'))
            ->unique();

        // Match on the normalised slug, not the raw label. Events carry the
        // scraper's spelling ("Timișoara") while users.city is free text from
        // the onboarding chat ("Timisoara"), so an exact comparison here drops
        // every candidate and empties the dashboard.
        $citySlug = EventTextNormalizer::citySlug($user->city);

        $candidates = Event::upcoming()
            ->visible()
            ->canonical()
            ->where('is_classified', true)
            ->when($citySlug !== null, fn ($q) => $q->where('city_slug', $citySlug))
            ->whereNotIn('id', $engagedEventIds)
            // Order before limiting: without it the 200 candidates are an
            // arbitrary slice, so the same user can get different results
            // from one request to the next.
            ->orderBy('starts_at')
            ->limit(200)
            // sourceMatch() reads every provider that reported the event; without
            // this each of the 200 candidates costs its own query.
            ->with('sources')
            ->get();

        // Score every candidate
        $scored = $candidates
            ->map(fn (Event $event) => ['event' => $event, 'score' => $this->scoreEvent($user, $event)])
            ->sortByDesc('score')
            ->values();

        // Discovery budget — driven by the user's openness (auto-tuned over time),
        // falling back to the global default.
        $explorationBudget = (float) ($user->discovery_openness
            ?? config('eventpulse.discovery.exploration_budget', 0.2));
        $discoveryCount = max(1, (int) round($limit * $explorationBudget));
        $recommendationCount = $limit - $discoveryCount;

        // Apply diversity filter, then take top N
        $diverseEvents = $this->diversityFilter->filter(
            $scored->pluck('event'),
        );
        $recommended = $diverseEvents->take($recommendationCount);

        $recommendedIds = $recommended->pluck('id')->toArray();

        // Discovery events, excluding what was just recommended so the same
        // event cannot occupy both sections of the dashboard.
        $discoveryEvents = $this->discoveryEngine->discoverForUser(
            $user,
            $discoveryCount,
            $recommendedIds,
        );

        // Average score of recommended set
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
     * Average profile score across the providers that reported the event.
     *
     * Learned from the reaction buttons: sources the user keeps saying yes to
     * pull their listings up, sources they keep dismissing push them down.
     * 0.0 when no source has a score yet.
     */
    public function sourceMatch(User $user, Event $event): float
    {
        return $this->profileScorer->calculateSourceScore(
            $user->interest_profile ?? [],
            $event->sourceKeys(),
        );
    }

    /**
     * 1.0 when user and event share the same city, 0.3 otherwise.
     * If the user has no city set, default to 0.5 (neutral).
     *
     * Compared on the normalised slug so scoring agrees with the candidate
     * filter in recommend() about diacritics as well as case.
     */
    public function locationMatch(User $user, Event $event): float
    {
        $userCitySlug = EventTextNormalizer::citySlug($user->city);

        if ($userCitySlug === null) {
            return 0.5;
        }

        return $userCitySlug === EventTextNormalizer::citySlug($event->city)
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
