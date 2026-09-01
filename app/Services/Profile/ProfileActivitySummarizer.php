<?php

declare(strict_types=1);

namespace App\Services\Profile;

use App\Enums\ActivityType;
use App\Enums\EventCategory;
use App\Enums\Reaction;
use App\Models\Event;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Models\UserEventReaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * What the user's own activity says about them, for the profile page. Kept out
 * of the controller so it stays a thin validate → delegate → respond, the same
 * way DashboardStatsBuilder serves the dashboard header.
 *
 * Everything here is derived on read. None of it is stored: the reaction and
 * bookmark tables are already the record, and a cached copy would only be one
 * more thing to invalidate on every reaction.
 */
class ProfileActivitySummarizer
{
    private const RECENT_LIMIT = 10;

    private const TOP_CATEGORY_LIMIT = 5;

    /**
     * @return array{
     *     reactions: array{interested: int, not_interested: int},
     *     saved: int,
     *     top_categories: list<array{category: string, count: int}>,
     *     recent: list<array{event_id: string, event_title: ?string, reaction: string, created_at: ?string}>,
     *     implicit: array{event_view: int, event_click: int, calendar_download: int, search: int},
     *     implicit_window_days: int,
     *     has_activity: bool,
     * }
     */
    public function build(User $user): array
    {
        $reactionCounts = $user->reactions()
            ->get(['reaction'])
            ->countBy(fn (UserEventReaction $reaction) => $reaction->reaction->value);

        $reactions = [
            'interested' => (int) ($reactionCounts[Reaction::Interested->value] ?? 0),
            'not_interested' => (int) ($reactionCounts[Reaction::NotInterested->value] ?? 0),
        ];

        // The total, not what /saved lists: BookmarkService::savedEventsFor()
        // shows only upcoming saves, so the two numbers legitimately differ and
        // the label on this one says "salvate" rather than "de văzut".
        $saved = $user->bookmarks()->count();

        $implicit = $this->implicitCounts($user);

        return [
            'reactions' => $reactions,
            'saved' => $saved,
            'top_categories' => $this->topLikedCategories($user),
            'recent' => $this->recentReactions($user),
            'implicit' => $implicit,
            'implicit_window_days' => $this->retentionDays(),
            'has_activity' => $reactions['interested'] > 0
                || $reactions['not_interested'] > 0
                || $saved > 0
                || array_sum($implicit) > 0,
        ];
    }

    /**
     * The categories behind the user's positive reactions.
     *
     * A join rather than a read of `interest_profile`: the profile blob is a
     * decayed, blended score that also absorbs onboarding and implicit signals,
     * so it cannot answer "what did I actually say yes to, and how often".
     *
     * @return list<array{category: string, count: int}>
     */
    private function topLikedCategories(User $user): array
    {
        $counts = Event::query()
            ->join('user_event_reactions', 'user_event_reactions.event_id', '=', 'events.id')
            ->where('user_event_reactions.user_id', $user->id)
            ->where('user_event_reactions.reaction', Reaction::Interested->value)
            ->groupBy('events.category')
            ->select('events.category')
            ->selectRaw('count(*) as hits')
            ->orderByDesc('hits')
            ->limit(self::TOP_CATEGORY_LIMIT)
            ->pluck('hits', 'category');

        // `events.category` is an unconstrained string column and pluck() casts
        // only the value column, never the key — so a row written around
        // EventClassifier would arrive here raw. Dropping it silently would put
        // a category in this card that ProfilePresenter refuses to show a few
        // hundred pixels above, with nothing anywhere saying why.
        return $counts
            ->map(function ($hits, $category) {
                if (EventCategory::tryFrom((string) $category) === null) {
                    Log::warning('Reaction counted against an unknown event category', [
                        'category' => (string) $category,
                    ]);

                    return null;
                }

                return ['category' => (string) $category, 'count' => (int) $hits];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<array{event_id: string, event_title: ?string, reaction: string, created_at: ?string}>
     */
    private function recentReactions(User $user): array
    {
        return $user->reactions()
            ->with('event:id,title')
            ->latest()
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->map(fn (UserEventReaction $reaction) => [
                'event_id' => $reaction->event_id,
                'event_title' => $reaction->event?->title,
                'reaction' => $reaction->reaction->value,
                'created_at' => $reaction->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Views, clicks, calendar downloads and searches.
     *
     * PruneActivityLogsJob drops rows past the retention window, so these are
     * emphatically not lifetime totals — the window is returned alongside them
     * so the page can say so.
     *
     * @return array{event_view: int, event_click: int, calendar_download: int, search: int}
     */
    private function implicitCounts(User $user): array
    {
        $types = [
            ActivityType::EventView,
            ActivityType::EventClick,
            ActivityType::CalendarDownload,
            ActivityType::Search,
        ];

        $counts = UserActivityLog::query()
            ->human()
            ->where('user_id', $user->id)
            ->ofType($types)
            ->where('created_at', '>=', Carbon::now()->subDays($this->retentionDays()))
            ->groupBy('type')
            ->select('type')
            ->selectRaw('count(*) as hits')
            ->pluck('hits', 'type');

        $result = [];

        foreach ($types as $type) {
            $result[$type->value] = (int) ($counts[$type->value] ?? 0);
        }

        /** @var array{event_view: int, event_click: int, calendar_download: int, search: int} $result */
        return $result;
    }

    private function retentionDays(): int
    {
        return max(1, (int) config('eventpulse.activity.retention_days', 180));
    }
}
