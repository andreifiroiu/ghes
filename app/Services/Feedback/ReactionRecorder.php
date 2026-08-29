<?php

declare(strict_types=1);

namespace App\Services\Feedback;

use App\Enums\ActivitySurface;
use App\Enums\ActivityType;
use App\Enums\Reaction;
use App\Jobs\ProcessFeedbackJob;
use App\Jobs\ReverseProfileDeltaJob;
use App\Models\User;
use App\Models\UserEventReaction;
use App\Services\Activity\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Single write path for taste signals, shared by the in-app feedback endpoint
 * and the signed email reaction links.
 */
class ReactionRecorder
{
    public function __construct(
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Record (or change) a user's reaction to an event.
     *
     * Re-submitting the same reaction is a deliberate no-op: no job, no profile
     * churn. Only a genuine change clears is_processed, which is what lets the
     * processor reverse the old delta before applying the new one.
     *
     * $surface only labels the activity row. The caller is the only party that
     * knows whether this came from the app or from a digest link, and telling
     * those apart is most of the point of logging reactions at all.
     */
    public function record(
        User $user,
        string $eventId,
        Reaction $reaction,
        ActivitySurface $surface = ActivitySurface::EventDetail,
    ): UserEventReaction {
        // firstOrCreate, not firstOrNew + save: two concurrent submits (the
        // email confirm page auto-submits *and* offers a button) would both miss
        // the select and both INSERT, and the loser would get a 500 off the
        // unique (user_id, event_id) index. firstOrCreate catches that and
        // returns the winner's row.
        $row = UserEventReaction::firstOrCreate(
            ['user_id' => $user->id, 'event_id' => $eventId],
            ['reaction' => $reaction, 'is_processed' => false],
        );

        $changed = $row->wasRecentlyCreated || $row->reaction !== $reaction;

        if ($changed && ! $row->wasRecentlyCreated) {
            $row->forceFill(['reaction' => $reaction, 'is_processed' => false])->save();
        }

        // Also re-dispatch for an unchanged reaction whose job never completed.
        // Nothing sweeps is_processed = false, so without this a row orphaned by
        // a lost job stays unscored forever and clicking the button again — the
        // only remedy a user could think of — would be a silent no-op.
        if ($changed || ! $row->is_processed) {
            ProcessFeedbackJob::dispatch($row->id, $user->id);
        }

        // Analytics only. The profile side of a reaction is owned by
        // FeedbackProcessor via the job above; this row exists so a user's
        // explicit and implicit signals can be read as one timeline. Logged
        // only on a real change, so a no-op re-submit stays a no-op here too.
        if ($changed) {
            $this->activity->log(
                ActivityType::forReaction($reaction),
                $surface,
                eventId: $eventId,
                user: $user,
            );
        }

        return $row;
    }

    /**
     * Remove a user's reaction and undo its contribution to the profile.
     *
     * The bookmark on the same event, if any, is deliberately untouched.
     */
    public function remove(
        User $user,
        string $eventId,
        ActivitySurface $surface = ActivitySurface::EventDetail,
    ): void {
        // Read and delete under the same row lock the processing job takes.
        // Without it this can read applied_deltas while ProcessFeedbackJob is
        // mid-flight, see null, delete the row, and skip the reversal — leaving
        // the delta in the profile with no ledger and nothing able to undo it.
        $appliedDeltas = DB::transaction(function () use ($user, $eventId): array {
            $row = UserEventReaction::query()
                ->where('user_id', $user->id)
                ->where('event_id', $eventId)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return [];
            }

            $deltas = $row->applied_deltas ?? [];

            $row->delete();

            return $deltas;
        });

        if ($appliedDeltas !== []) {
            ReverseProfileDeltaJob::dispatch($user->id, $eventId, $appliedDeltas);
        }

        $this->activity->log(
            ActivityType::ReactionCleared,
            $surface,
            eventId: $eventId,
            user: $user,
        );
    }
}
