<?php

declare(strict_types=1);

namespace App\Services\InterestProfile;

use App\Enums\Reaction;
use App\Models\Event;
use App\Models\User;

class ProfileUpdater
{
    /**
     * Update a user's interest profile based on their reaction to an event.
     *
     * Applies the reaction's category and tag deltas (from config) to the
     * event's category score and tag scores, clamps everything to [0.0, 1.0],
     * maintains negative tags (suppression filters), and saves.
     */
    public function updateFromFeedback(User $user, Event $event, string $reaction): void
    {
        /** @var array<string, array{category: float, tag: float}> $deltas */
        $deltas = config('eventpulse.feedback.deltas');
        $delta = $deltas[$reaction] ?? null;

        if ($delta === null) {
            return;
        }

        $categoryDelta = (float) ($delta['category'] ?? 0.0);
        $tagDelta = (float) ($delta['tag'] ?? 0.0);

        $profile = $user->interest_profile ?? [];

        // Update category score
        if ($categoryDelta !== 0.0) {
            $categoryKey = $event->category->value;
            $currentCategoryScore = (float) ($profile[$categoryKey] ?? 0.0);
            $profile[$categoryKey] = $this->clampScore($currentCategoryScore + $categoryDelta);
        }

        // Update tag scores
        if ($tagDelta !== 0.0) {
            foreach ($event->tags ?? [] as $tag) {
                $tagKey = "tag:{$tag}";
                $currentTagScore = (float) ($profile[$tagKey] ?? 0.0);
                $profile[$tagKey] = $this->clampScore($currentTagScore + $tagDelta);
            }
        }

        $profile = $this->applyNegativeTags($profile, $event, $reaction);

        $user->update(['interest_profile' => $profile]);
    }

    /**
     * Maintain the user's negative tags (stored as "negtag:{tag}" keys and used
     * as hard filters in recommendation). "Hide like this" suppresses the
     * event's tags; an explicit positive reaction clears any prior suppression.
     *
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function applyNegativeTags(array $profile, Event $event, string $reaction): array
    {
        $tags = $event->tags ?? [];

        if ($tags === []) {
            return $profile;
        }

        if ($reaction === Reaction::Hidden->value) {
            foreach ($tags as $tag) {
                $profile["negtag:{$tag}"] = 1.0;
            }
        } elseif (in_array($reaction, [Reaction::Interested->value, Reaction::Saved->value], true)) {
            foreach ($tags as $tag) {
                unset($profile["negtag:{$tag}"]);
            }
        }

        return $profile;
    }

    /**
     * Clamp a score to the valid range [0.0, 1.0].
     */
    public function clampScore(float $score): float
    {
        return max(0.0, min(1.0, $score));
    }
}
