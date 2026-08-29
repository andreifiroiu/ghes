<?php

declare(strict_types=1);

namespace App\Services\InterestProfile;

use App\Models\Event;
use App\Models\User;

class ProfileScorer
{
    /**
     * Calculate an overall relevance score for an event based on the user's profile.
     *
     * Combines category and tag scores using configured weights.
     */
    public function scoreForEvent(User $user, Event $event): float
    {
        $profile = $user->interest_profile ?? [];
        $weights = config('eventpulse.recommendation.weights');

        $categoryScore = $this->calculateCategoryScore($profile, $event->category->value);
        $tagScore = $this->calculateTagScore($profile, $event->tags ?? []);
        $sourceScore = $this->calculateSourceScore($profile, $event->sourceKeys());

        $catWeight = (float) ($weights['category'] ?? 0.3);
        $tagWeight = (float) ($weights['tags'] ?? 0.2);
        // Absent means off, matching RecommendationEngine — a config that predates
        // source scoring keeps exactly its old category/tag balance.
        $sourceWeight = (float) ($weights['source'] ?? 0.0);
        $totalWeight = $catWeight + $tagWeight + $sourceWeight;

        if ($totalWeight === 0.0) {
            return 0.0;
        }

        $score = (($catWeight * $categoryScore)
            + ($tagWeight * $tagScore)
            + ($sourceWeight * $sourceScore)) / $totalWeight;

        return max(0.0, min(1.0, $score));
    }

    /**
     * Get the user's profile score for a specific category.
     *
     * @param  array<string, float>  $profile  User's interest profile
     * @param  string  $category  Event category name
     * @return float Score between 0.0 and 1.0
     */
    public function calculateCategoryScore(array $profile, string $category): float
    {
        return (float) ($profile[$category] ?? 0.0);
    }

    /**
     * Calculate average profile score across matching tags.
     *
     * For each tag in the event, look up the user's profile score for that tag.
     * Returns the average of all matching tag scores, or 0 if no tags match.
     *
     * @param  array<string, float>  $profile  User's interest profile
     * @param  array<int, string>  $tags  Event tags
     * @return float Average score between 0.0 and 1.0
     */
    public function calculateTagScore(array $profile, array $tags): float
    {
        if (empty($tags)) {
            return 0.0;
        }

        $scores = array_map(
            fn (string $tag) => (float) ($profile["tag:{$tag}"] ?? 0.0),
            $tags,
        );

        $sum = array_sum($scores);

        return max(0.0, min(1.0, $sum / count($scores)));
    }

    /**
     * Calculate average profile score across the providers that reported an event.
     *
     * Deduped events carry several sources; averaging rather than summing keeps
     * a widely-listed event from outscoring a niche one on provenance alone.
     *
     * @param  array<string, float>  $profile  User's interest profile
     * @param  array<int, string>  $sources  Provider keys, e.g. ["iabilet"]
     * @return float Average score between 0.0 and 1.0
     */
    public function calculateSourceScore(array $profile, array $sources): float
    {
        if ($sources === []) {
            return 0.0;
        }

        $scores = array_map(
            fn (string $source) => (float) ($profile["source:{$source}"] ?? 0.0),
            $sources,
        );

        return max(0.0, min(1.0, array_sum($scores) / count($scores)));
    }
}
