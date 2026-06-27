<?php

declare(strict_types=1);

namespace App\Services\Recommendation;

use App\Models\Event;
use App\Models\Notification;
use App\Models\UserEventReaction;
use App\Services\InterestProfile\ProfileUpdater;
use Illuminate\Support\Facades\Log;

class FeedbackProcessor
{
    public function __construct(
        private readonly ProfileUpdater $profileUpdater,
    ) {}

    /**
     * Process a single reaction: update the user's profile and mark processed.
     */
    public function processReaction(UserEventReaction $reaction): void
    {
        if ($reaction->is_processed) {
            return;
        }

        $reaction->loadMissing(['user', 'event']);

        $this->profileUpdater->updateFromFeedback(
            $reaction->user,
            $reaction->event,
            $reaction->reaction->value,
        );

        $reaction->update(['is_processed' => true]);

        Log::debug('Processed feedback', [
            'reaction' => $reaction->reaction->value,
            'user_id' => $reaction->user_id,
            'event_id' => $reaction->event_id,
        ]);
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

        $reactedEventIds = UserEventReaction::query()
            ->where('user_id', $user->id)
            ->whereIn('event_id', $eventIds)
            ->pluck('event_id')
            ->all();

        $ignoredEventIds = array_diff($eventIds, $reactedEventIds);

        if ($ignoredEventIds === []) {
            return 0;
        }

        $count = 0;

        foreach (Event::whereIn('id', $ignoredEventIds)->get() as $event) {
            $this->profileUpdater->updateFromFeedback($user, $event, 'ignored');
            $count++;
        }

        return $count;
    }
}
