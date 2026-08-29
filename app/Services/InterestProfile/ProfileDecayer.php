<?php

declare(strict_types=1);

namespace App\Services\InterestProfile;

use App\Models\User;

class ProfileDecayer
{
    /**
     * Apply time-based decay to a single user's interest profile.
     *
     * Multiplies all profile scores by (1 - decay_rate) to gradually
     * reduce stale preferences over time. This ensures the profile
     * reflects recent behavior more than old behavior.
     */
    public function decay(User $user): void
    {
        $decayRate = config('eventpulse.profile.decay_rate', 0.05);
        $profile = $user->interest_profile ?? [];

        if (empty($profile)) {
            return;
        }

        $multiplier = 1.0 - $decayRate;

        $decayed = [];
        foreach ($profile as $key => $value) {
            $decayed[$key] = is_numeric($value)
                ? max(0.0, min(1.0, (float) $value * $multiplier))
                : $value;
        }

        $user->update(['interest_profile' => $decayed]);
    }

    /**
     * Apply decay to all user profiles.
     *
     * Iterates through all users and decays their profiles.
     * Returns the number of profiles processed.
     */
    public function decayAll(): int
    {
        $count = 0;

        User::whereNotNull('interest_profile')
            ->where('interest_profile', '!=', '{}')
            ->chunkById(100, function ($users) use (&$count) {
                foreach ($users as $user) {
                    $this->decay($user);
                    $count++;
                }
            });

        return $count;
    }
}
