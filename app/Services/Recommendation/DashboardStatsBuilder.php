<?php

declare(strict_types=1);

namespace App\Services\Recommendation;

use App\Enums\EventCategory;
use App\Enums\Reaction;
use App\Models\Event;
use App\Models\User;
use App\Services\Processing\EventTextNormalizer;

/**
 * Counts shown in the dashboard header. Kept out of the controller so it stays
 * a thin validate → delegate → respond.
 */
class DashboardStatsBuilder
{
    /**
     * @return array{upcoming: int, saved: int, interested: int, profile_completeness: int}
     */
    public function build(User $user): array
    {
        return [
            'upcoming' => $this->upcomingInUserCity($user),
            'saved' => $user->bookmarks()->count(),
            'interested' => $user->reactions()
                ->where('reaction', Reaction::Interested->value)
                ->count(),
            'profile_completeness' => $this->profileCompleteness($user),
        ];
    }

    /**
     * Upcoming events the user could actually be shown — the same gates the
     * recommendation query applies, minus the personalisation. A zero here
     * means the empty dashboard is a data problem, not a scoring one.
     */
    public function upcomingInUserCity(User $user): int
    {
        $citySlug = EventTextNormalizer::citySlug($user->city);

        return Event::upcoming()
            ->visible()
            ->canonical()
            ->where('is_classified', true)
            ->when($citySlug !== null, fn ($query) => $query->where('city_slug', $citySlug))
            ->count();
    }

    /**
     * How much of the interest profile is filled in, as a percentage of the
     * scoreable categories.
     *
     * Tag and source keys are ignored: they accumulate from feedback rather
     * than from onboarding, so counting them would make a complete profile
     * look permanently unfinished. `Other` is excluded from the denominator
     * for the same reason — it is the classifier's fallback bucket and
     * ProfileGenerator never assigns it a score, so counting it would cap
     * every fully-onboarded user at 13/14 and leave the "finish your profile"
     * hint showing forever with no way to clear it.
     */
    private function profileCompleteness(User $user): int
    {
        $profile = $user->interest_profile ?? [];

        $scoreable = array_filter(
            EventCategory::cases(),
            fn (EventCategory $category) => $category !== EventCategory::Other,
        );

        $scored = collect($scoreable)
            ->filter(fn (EventCategory $category) => ($profile[$category->value] ?? 0.0) > 0.0)
            ->count();

        return (int) round($scored / max(1, count($scoreable)) * 100);
    }
}
