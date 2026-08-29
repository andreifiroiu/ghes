<?php

declare(strict_types=1);

namespace App\Services\InterestProfile;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class ProfileDecayer
{
    /**
     * Apply time-based decay to a single user's interest profile.
     *
     * Multiplies all profile scores by (1 - decay_rate) to gradually
     * reduce stale preferences over time. This ensures the profile
     * reflects recent behavior more than old behavior.
     *
     * Returns whether the user had a profile to decay.
     */
    public function decay(User $user): bool
    {
        $decayRate = config('eventpulse.profile.decay_rate', 0.05);
        $profile = $user->interest_profile ?? [];

        if ($profile === []) {
            return false;
        }

        $multiplier = 1.0 - $decayRate;

        $decayed = [];
        foreach ($profile as $key => $value) {
            $decayed[$key] = is_numeric($value)
                ? max(0.0, min(1.0, (float) $value * $multiplier))
                : $value;
        }

        $user->update(['interest_profile' => $decayed]);

        return true;
    }

    /**
     * Apply decay to all user profiles.
     *
     * Returns the number of profiles that actually had scores to decay.
     *
     * Empty profiles are skipped in PHP rather than in the query: on
     * PostgreSQL the column is `json`, which has no equality operator, so
     * comparing it against '{}' in SQL fails outright.
     */
    public function decayAll(): int
    {
        $count = 0;

        User::query()
            ->whereNotNull('interest_profile')
            ->orderBy('id')
            ->chunkById(100, function (Collection $users) use (&$count): void {
                /** @var Collection<int, User> $users */
                foreach ($users as $user) {
                    if ($this->decay($user)) {
                        $count++;
                    }
                }
            });

        return $count;
    }
}
