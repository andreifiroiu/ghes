<?php

declare(strict_types=1);

namespace App\Services\Bookmarks;

use App\Enums\ActivitySurface;
use App\Enums\ActivityType;
use App\Jobs\ProcessBookmarkJob;
use App\Jobs\ReverseProfileDeltaJob;
use App\Models\Event;
use App\Models\EventBookmark;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bookmarks ("Salvează") are independent of taste signals: saving an event
 * never changes the user's reaction to it, and un-saving never removes one.
 */
class BookmarkService
{
    public function __construct(
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Bookmark an event. Idempotent — saving twice does not double-count.
     */
    public function add(
        User $user,
        string $eventId,
        ActivitySurface $surface = ActivitySurface::EventDetail,
    ): EventBookmark {
        $bookmark = EventBookmark::firstOrCreate([
            'user_id' => $user->id,
            'event_id' => $eventId,
        ]);

        // Re-dispatch for an existing bookmark whose job never completed, too:
        // nothing sweeps is_processed = false, so otherwise a row orphaned by a
        // lost job never contributes its delta and re-saving cannot fix it.
        if ($bookmark->wasRecentlyCreated || ! $bookmark->is_processed) {
            ProcessBookmarkJob::dispatch($bookmark->id, $user->id);
        }

        // Analytics only — the profile delta is the job's business. Skipped for
        // an idempotent re-save so the timeline shows one save, not one per
        // double-click.
        if ($bookmark->wasRecentlyCreated) {
            $this->activity->log(
                ActivityType::BookmarkAdded,
                $surface,
                eventId: $eventId,
                user: $user,
            );
        }

        return $bookmark;
    }

    /**
     * Remove a bookmark and undo only its own share of the profile.
     */
    public function remove(
        User $user,
        string $eventId,
        ActivitySurface $surface = ActivitySurface::EventDetail,
    ): void {
        // Same row lock as ProcessBookmarkJob takes: reading applied_deltas
        // while that job is mid-flight would see null, delete the row, and skip
        // the reversal, stranding the saved delta in the profile permanently.
        $appliedDeltas = DB::transaction(function () use ($user, $eventId): array {
            $bookmark = EventBookmark::query()
                ->where('user_id', $user->id)
                ->where('event_id', $eventId)
                ->lockForUpdate()
                ->first();

            if ($bookmark === null) {
                return [];
            }

            $deltas = $bookmark->applied_deltas ?? [];

            $bookmark->delete();

            return $deltas;
        });

        if ($appliedDeltas !== []) {
            ReverseProfileDeltaJob::dispatch($user->id, $eventId, $appliedDeltas);
        }

        $this->activity->log(
            ActivityType::BookmarkRemoved,
            $surface,
            eventId: $eventId,
            user: $user,
        );
    }

    /**
     * Upcoming events the user has bookmarked, soonest first.
     *
     * @return Collection<int, Event>
     */
    public function savedEventsFor(User $user): Collection
    {
        $bookmarkedEventIds = $user->bookmarks()->pluck('event_id');

        return Event::whereIn('id', $bookmarkedEventIds)
            ->visible()
            ->canonical()
            ->withUserContext($user)
            ->orderBy('starts_at')
            ->get();
    }
}
