<?php

declare(strict_types=1);

namespace App\Services\InterestProfile;

use App\Enums\EventCategory;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Splits the flat `interest_profile` blob into the three families the UI reads.
 *
 * The column is a single flat map — `['music' => 0.9, 'tag:jazz' => 0.8,
 * 'source:iabilet' => 0.7]` — because ProfileUpdater writes category, tag and
 * source deltas into one row under one lock. Every consumer therefore has to
 * split it back by key prefix, and doing that in JavaScript is what left the
 * profile page reading a `categories` sub-key that nothing has ever written.
 */
class ProfilePresenter
{
    /**
     * Tags below this score are noise: a single reaction seeds a tag at 0.20
     * and passive decay never quite clears it, so a user would see dozens of
     * tags they never expressed an opinion about. Matches the threshold the
     * end-of-chat preview card already applies.
     */
    private const TAG_FLOOR = 0.3;

    /**
     * @return array{
     *     categories: list<array{key: string, score: float}>,
     *     tags: list<array{key: string, score: float}>,
     *     sources: list<array{key: string, score: float}>,
     * }
     */
    public function present(User $user): array
    {
        $categories = [];
        $tags = [];
        $sources = [];

        $validCategories = array_map(
            fn (EventCategory $category) => $category->value,
            EventCategory::cases(),
        );

        foreach ($user->interest_profile ?? [] as $key => $value) {
            // `city`, `price_sensitive` and `preferred_times` were written into
            // this blob by older profile generations, and ProfileDecayer still
            // steps over non-numeric values rather than dropping them.
            if (! is_numeric($value)) {
                continue;
            }

            $key = (string) $key;
            $score = (float) $value;

            if (str_starts_with($key, 'tag:')) {
                if ($score >= self::TAG_FLOOR) {
                    $tags[] = ['key' => substr($key, 4), 'score' => $score];
                }

                continue;
            }

            if (str_starts_with($key, 'source:')) {
                $sources[] = ['key' => substr($key, 7), 'score' => $score];

                continue;
            }

            if (in_array($key, $validCategories, true)) {
                $categories[] = ['key' => $key, 'score' => $score];

                continue;
            }

            // Not a data condition. A scored key belonging to no family means a
            // writer and EventCategory have drifted apart, and the score stays
            // invisible to the user while still steering their recommendations
            // — the same shape as the bug this class exists to fix.
            Log::warning('Unrecognised interest_profile key', [
                'user_id' => $user->id,
                'key' => $key,
            ]);
        }

        return [
            'categories' => $this->sorted($categories),
            'tags' => $this->sorted($tags),
            'sources' => $this->sorted($sources),
        ];
    }

    /**
     * @param  list<array{key: string, score: float}>  $entries
     * @return list<array{key: string, score: float}>
     */
    private function sorted(array $entries): array
    {
        usort($entries, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return $entries;
    }
}
