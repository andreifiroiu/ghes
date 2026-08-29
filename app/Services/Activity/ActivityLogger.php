<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Enums\ActivitySurface;
use App\Enums\ActivityType;
use App\Models\User;
use App\Models\UserActivityLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The single write path into `user_activity_logs`.
 *
 * Deliberately pure: it records what happened and nothing else. Deciding
 * whether a recorded click may move someone's interest profile is a policy
 * question with one answer in one place (ActivityController), not something
 * that should fire as a side effect of writing a row — a reaction and a
 * bookmark also land here, and their profile deltas are already owned by
 * FeedbackProcessor.
 *
 * Writes are synchronous. A single local INSERT is nowhere near the threshold
 * that would justify a queue hop, and a page render costs one statement because
 * impressions go through logMany().
 */
class ActivityLogger
{
    public function __construct(
        private readonly RequestFingerprint $fingerprint,
    ) {}

    /**
     * Record one action.
     *
     * Never throws. Analytics is the least important thing happening in any
     * request that reaches here — a logging failure must not cost the user
     * their redirect, their page, or their reaction.
     *
     * @param  array<string, mixed>  $context
     */
    public function log(
        ActivityType $type,
        ActivitySurface $surface,
        ?string $eventId = null,
        ?User $user = null,
        ?string $notificationId = null,
        array $context = [],
    ): ?UserActivityLog {
        try {
            $botReason = $this->fingerprint->botReason();

            return UserActivityLog::create([
                'user_id' => $user?->id,
                'event_id' => $eventId,
                'notification_id' => $notificationId,
                'type' => $type,
                'surface' => $surface,
                'session_key' => $this->fingerprint->sessionKey(),
                'is_bot' => $botReason !== null,
                'context' => $botReason === null ? $context : [...$context, 'bot_reason' => $botReason],
            ]);
        } catch (\Throwable $e) {
            $this->reportFailure($type, $e);

            return null;
        }
    }

    /**
     * Record the same action against many events at once.
     *
     * This is what makes server-side impression logging affordable: a rendered
     * page of twenty cards is one INSERT, not twenty. Bypasses Eloquent, so ids
     * and timestamps are built by hand.
     *
     * $serverOriginated marks a batch our own code wrote rather than one a
     * client fetched — digest impressions, logged from a queue worker where
     * there is no browser and so no User-Agent. Classifying those by the
     * absent header would flag every one as a bot and drop them straight back
     * out of the click-through denominator they exist to provide.
     *
     * @param  list<string>  $eventIds
     * @param  array<string, mixed>  $context
     * @return int rows written
     */
    public function logMany(
        ActivityType $type,
        ActivitySurface $surface,
        array $eventIds,
        ?User $user = null,
        ?string $notificationId = null,
        array $context = [],
        bool $serverOriginated = false,
    ): int {
        if ($eventIds === []) {
            return 0;
        }

        // Everything below sits inside the try, fingerprinting and JSON
        // encoding included. log() promises never to throw and every caller
        // relies on it; a JsonException on a caller-supplied context must not
        // be the one thing that 500s a page render.
        try {
            $now = now();
            $sessionKey = $serverOriginated ? null : $this->fingerprint->sessionKey();
            $botReason = $serverOriginated ? null : $this->fingerprint->botReason();
            $encodedContext = json_encode(
                $botReason === null ? $context : [...$context, 'bot_reason' => $botReason],
                JSON_THROW_ON_ERROR,
            );

            $rows = array_map(fn (string $eventId): array => [
                'id' => (string) Str::uuid(),
                'user_id' => $user?->id,
                'event_id' => $eventId,
                'notification_id' => $notificationId,
                'type' => $type->value,
                'surface' => $surface->value,
                'session_key' => $sessionKey,
                'is_bot' => $botReason !== null,
                'context' => $encodedContext,
                'created_at' => $now,
                'updated_at' => $now,
            ], array_values(array_unique($eventIds)));

            UserActivityLog::insert($rows);

            return count($rows);
        } catch (\Throwable $e) {
            // All-or-nothing: one bad row loses the whole batch. That matters
            // more here than for a single insert, because impressions are the
            // click-through denominator — losing a batch moves the rate *up*.
            $this->reportFailure($type, $e, ['rows' => count($eventIds)]);

            return 0;
        }
    }

    /**
     * A swallowed write is invisible by construction — the caller carries on and
     * reports success — so it has to be loud somewhere, or the first sign of
     * trouble is a dashboard that has quietly been flat for a week.
     */
    /**
     * @param  array<string, mixed>  $extra
     */
    private function reportFailure(ActivityType $type, \Throwable $e, array $extra = []): void
    {
        Log::error('ActivityLogger: failed to record activity', [
            'type' => $type->value,
            'error' => $e->getMessage(),
            ...$extra,
        ]);
    }
}
