<?php

declare(strict_types=1);

namespace App\Services\Recommendation;

use App\Models\DiscoveryLog;
use App\Models\Event;
use App\Models\Notification;
use App\Models\UserEventReaction;
use App\Services\InterestProfile\ProfileUpdater;
use Illuminate\Support\Facades\Log;

class FeedbackProcessor
{
    public function __construct(
        private readonly ProfileUpdater $profileUpdater,
        private readonly DiscoveryEngine $discoveryEngine,
    ) {}

    /**
     * Process a single reaction: update the user's profile and mark processed.
     *
     * When the reaction is to a discovery event, the exploration reward/penalty
     * is applied, the discovery outcome is recorded, and the user's openness is
     * recalibrated.
     */
    public function processReaction(UserEventReaction $reaction): void
    {
        if ($reaction->is_processed) {
            return;
        }

        $reaction->loadMissing(['user', 'event']);

        $reactionValue = $reaction->reaction->value;

        $discoveryLog = DiscoveryLog::query()
            ->where('user_id', $reaction->user_id)
            ->where('event_id', $reaction->event_id)
            ->first();

        $this->profileUpdater->updateFromFeedback(
            $reaction->user,
            $reaction->event,
            $reactionValue,
            isDiscovery: $discoveryLog !== null,
        );

        if ($discoveryLog !== null) {
            $discoveryLog->update(['outcome' => $reactionValue]);
            $this->discoveryEngine->recalibrateOpenness($reaction->user);
        }

        $reaction->update(['is_processed' => true]);

        Log::debug('Processed feedback', [
            'reaction' => $reactionValue,
            'user_id' => $reaction->user_id,
            'event_id' => $reaction->event_id,
            'is_discovery' => $discoveryLog !== null,
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
        $hadDiscovery = false;

        foreach (Event::whereIn('id', $ignoredEventIds)->get() as $event) {
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
