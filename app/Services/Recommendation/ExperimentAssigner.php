<?php

declare(strict_types=1);

namespace App\Services\Recommendation;

use App\Models\User;

class ExperimentAssigner
{
    /**
     * Resolve (assigning and persisting on first use) the user's recommendation
     * weight variant. Assignment is deterministic from the user id so it's
     * stable, and stored so attribution survives config changes.
     */
    public function variantFor(User $user): string
    {
        if (is_string($user->experiment_variant) && $user->experiment_variant !== '') {
            return $user->experiment_variant;
        }

        $variants = array_keys($this->variantSets());

        if ($variants === []) {
            return 'control';
        }

        $variant = $variants[crc32($user->id) % count($variants)];

        $user->update(['experiment_variant' => $variant]);

        return $variant;
    }

    /**
     * The recommendation weights for the user's variant (falls back to the
     * global default weights).
     *
     * @return array<string, float>
     */
    public function weightsFor(User $user): array
    {
        $sets = $this->variantSets();
        $variant = $this->variantFor($user);

        /** @var array<string, float> $weights */
        $weights = $sets[$variant] ?? config('eventpulse.recommendation.weights');

        return $weights;
    }

    /**
     * @return array<string, array<string, float>>
     */
    private function variantSets(): array
    {
        /** @var array<string, array<string, float>> $sets */
        $sets = config('eventpulse.experiments.recommendation_weights', []);

        return $sets;
    }
}
