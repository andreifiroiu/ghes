<?php

declare(strict_types=1);

namespace App\Services\Recommendation;

use App\Models\DiscoveryLog;
use App\Models\Event;
use App\Models\EventBookmark;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserEventReaction;
use App\Services\InterestProfile\ProfileUpdater;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FeedbackProcessor
{
    public function __construct(
        private readonly ProfileUpdater $profileUpdater,
        private readonly DiscoveryEngine $discoveryEngine,
    ) {}

    /**
     * Reconcile a reaction row against the user's profile.
     *
     * Reverses whatever this row previously contributed (its applied_deltas
     * ledger) before applying the current reaction, so changing your mind
     * re-scores correctly instead of stacking a second delta on top of the
     * first. The ledger stores the *effective* post-clamp change, which is what
     * makes the reversal exact at the [0,1] boundaries.
     *
     * When the reaction is to a discovery event, the exploration reward/penalty
     * is applied, the discovery outcome is recorded, and the user's openness is
     * recalibrated.
     */
    public function processReaction(UserEventReaction $reaction): void
    {
        DB::transaction(function () use ($reaction): void {
            // Re-read under a row lock. Without it, a user changing their mind
            // mid-job races us: record() would overwrite `reaction` while we
            // still hold the old value, and our completion write would then set
            // is_processed = true over the new reaction — stamping the new
            // reaction with the old reaction's ledger, permanently. The lock
            // also makes remove() wait for the ledger instead of reading null
            // and dropping the reversal.
            $fresh = UserEventReaction::query()
                ->whereKey($reaction->getKey())
                ->lockForUpdate()
                ->first();

            if ($fresh === null || $fresh->is_processed) {
                return;
            }

            // event.sources, not just event: deltaKeysFor() credits every provider
            // that reported it, and processUnprocessed() runs this in a loop.
            $fresh->loadMissing(['user', 'event.sources']);

            $reactionValue = $fresh->reaction->value;

            $isDiscovery = DiscoveryLog::query()
                ->where('user_id', $fresh->user_id)
                ->where('event_id', $fresh->event_id)
                ->exists();

            $this->profileUpdater->revert($fresh->user, $fresh->applied_deltas ?? []);

            $applied = $this->profileUpdater->apply(
                $fresh->user,
                $this->profileUpdater->deltaKeysFor($fresh->event, $reactionValue, isDiscovery: $isDiscovery),
            );

            $fresh->update([
                'applied_deltas' => $applied === [] ? null : $applied,
                'is_processed' => true,
            ]);

            $this->syncDiscoveryOutcome($fresh->user, $fresh->event_id);

            Log::debug('Processed feedback', [
                'reaction' => $reactionValue,
                'user_id' => $fresh->user_id,
                'event_id' => $fresh->event_id,
                'is_discovery' => $isDiscovery,
            ]);
        });
    }

    /**
     * Apply a bookmark's profile delta and record its reversal ledger.
     *
     * Bookmarks carry their own "saved" delta and stack with any reaction on the
     * same event, so this never reads or writes the reaction row.
     */
    public function processBookmark(EventBookmark $bookmark): void
    {
        DB::transaction(function () use ($bookmark): void {
            // Same row lock as processReaction, for the same reason: remove()
            // must not read applied_deltas before we have written it, or the
            // delta we are about to apply is stranded in the profile with no
            // ledger and nothing that could ever reverse it.
            $fresh = EventBookmark::query()
                ->whereKey($bookmark->getKey())
                ->lockForUpdate()
                ->first();

            if ($fresh === null || $fresh->is_processed) {
                return;
            }

            $fresh->loadMissing(['user', 'event.sources']);

            $isDiscovery = DiscoveryLog::query()
                ->where('user_id', $fresh->user_id)
                ->where('event_id', $fresh->event_id)
                ->exists();

            $applied = $this->profileUpdater->apply(
                $fresh->user,
                $this->profileUpdater->deltaKeysFor($fresh->event, 'saved', isDiscovery: $isDiscovery),
            );

            $fresh->update([
                'applied_deltas' => $applied === [] ? null : $applied,
                'is_processed' => true,
            ]);

            $this->syncDiscoveryOutcome($fresh->user, $fresh->event_id);

            Log::debug('Processed bookmark', [
                'user_id' => $fresh->user_id,
                'event_id' => $fresh->event_id,
            ]);
        });
    }

    /**
     * Undo a signal that has been removed: subtract its recorded delta and
     * recompute the discovery outcome from whatever signals survive.
     *
     * A null/empty ledger means unknown provenance (a row that predates the
     * ledger, or one migrated from the old `hidden`/`saved` reactions) and is a
     * deliberate no-op rather than a guess.
     *
     * @param  array<string, float>  $appliedDeltas
     */
    public function reverseSignal(User $user, string $eventId, array $appliedDeltas): void
    {
        $this->profileUpdater->revert($user, $appliedDeltas);

        $this->syncDiscoveryOutcome($user, $eventId);

        Log::debug('Reversed signal', [
            'user_id' => $user->id,
            'event_id' => $eventId,
            'keys' => array_keys($appliedDeltas),
        ]);
    }

    /**
     * Point `discovery_logs.outcome` at whatever the user's surviving signals
     * say about this event.
     *
     * Reactions and bookmarks are independent but share this one column, so
     * last-writer-wins is wrong in both directions: removing a bookmark would
     * erase the outcome a still-present "interested" earned, and reacting
     * "not_interested" to an event you have saved would record a thumbs-down as
     * a discovery hit. A bookmark is the strongest signal, so it wins; failing
     * that the reaction stands; with neither the exploration is unresolved again.
     */
    private function syncDiscoveryOutcome(User $user, string $eventId): void
    {
        $discoveryLog = DiscoveryLog::query()
            ->where('user_id', $user->id)
            ->where('event_id', $eventId)
            ->first();

        if ($discoveryLog === null) {
            return;
        }

        $bookmarked = EventBookmark::query()
            ->where('user_id', $user->id)
            ->where('event_id', $eventId)
            ->exists();

        // value() returns the cast enum, not the raw column; unwrap it here
        // rather than relying on the query grammar to convert it on the way out.
        $reaction = UserEventReaction::query()
            ->where('user_id', $user->id)
            ->where('event_id', $eventId)
            ->value('reaction');

        $outcome = $bookmarked ? 'saved' : $reaction?->value;

        $discoveryLog->update(['outcome' => $outcome]);

        $this->discoveryEngine->recalibrateOpenness($user);
    }

    /**
     * Process all unprocessed reactions and return how many were handled.
     */
    public function processUnprocessed(): int
    {
        $reactions = UserEventReaction::where('is_processed', false)
            ->with(['user', 'event'])
            ->get();

        $count = 0;

        foreach ($reactions as $reaction) {
            try {
                $this->processReaction($reaction);
                $count++;
            } catch (\Throwable $e) {
                Log::error('Failed to process reaction', [
                    'reaction_id' => $reaction->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info("Processed {$count} reactions");

        return $count;
    }

    /**
     * Apply passive "ignored" decay for events the user was shown but never
     * reacted to. Scans notification batches older than the configured window
     * that haven't been decayed yet, applies the "ignored" delta to each
     * un-reacted event's category, and marks the batch processed.
     *
     * @return int Number of (user, event) pairs decayed.
     */
    public function applyPassiveDecay(): int
    {
        $windowHours = (int) config('eventpulse.feedback.ignored_window_hours', 72);
        $cutoff = now()->subHours($windowHours);

        $decayed = 0;

        Notification::query()
            ->whereNull('decay_applied_at')
            ->whereNotNull('sent_at')
            ->where('sent_at', '<', $cutoff)
            ->with('user')
            ->chunkById(100, function ($notifications) use (&$decayed): void {
                foreach ($notifications as $notification) {
                    $decayed += $this->decayNotification($notification);
                    $notification->update(['decay_applied_at' => now()]);
                }
            });

        Log::info("Applied passive decay to {$decayed} ignored events");

        return $decayed;
    }

    /**
     * Decay every event in a notification batch the user did not react to.
     */
    private function decayNotification(Notification $notification): int
    {
        $user = $notification->user;

        if ($user === null) {
            return 0;
        }

        $eventIds = array_merge(
            $notification->event_ids ?? [],
            $notification->discovery_event_ids ?? [],
        );

        if ($eventIds === []) {
            return 0;
        }

        $engagedEventIds = UserEventReaction::query()
            ->where('user_id', $user->id)
            ->whereIn('event_id', $eventIds)
            ->pluck('event_id')
            ->merge(
                EventBookmark::query()
                    ->where('user_id', $user->id)
                    ->whereIn('event_id', $eventIds)
                    ->pluck('event_id'),
            )
            ->unique()
            ->all();

        $ignoredEventIds = array_diff($eventIds, $engagedEventIds);

        if ($ignoredEventIds === []) {
            return 0;
        }

        $count = 0;
        $hadDiscovery = false;

        foreach (Event::whereIn('id', $ignoredEventIds)->with('sources')->get() as $event) {
            $discoveryLog = DiscoveryLog::query()
                ->where('user_id', $user->id)
                ->where('event_id', $event->id)
                ->whereNull('outcome')
                ->first();

            $this->profileUpdater->updateFromFeedback(
                $user,
                $event,
                'ignored',
                isDiscovery: $discoveryLog !== null,
            );

            if ($discoveryLog !== null) {
                $discoveryLog->update(['outcome' => 'ignored']);
                $hadDiscovery = true;
            }

            $count++;
        }

        if ($hadDiscovery) {
            $this->discoveryEngine->recalibrateOpenness($user);
        }

        return $count;
    }
}
