<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\DTOs\ActivityBatchResult;
use App\DTOs\ClientActivityEvent;
use App\Models\Event;
use App\Models\User;
use App\Models\UserActivityLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Writes a batch of client-observed actions into `user_activity_logs`.
 *
 * The one write path besides ActivityLogger, and deliberately not built on
 * it: the logger swallows failures because analytics must never cost a user
 * their page, whereas here the write *is* the request. Answering 202 to a
 * batch that was not stored would make the app empty its buffer and lose
 * the rows for good; a 500 leaves them queued, and the replay is idempotent.
 *
 * Rows are analytics only. A batched click never reaches the interest
 * profile: that signal is dispatched by `POST /events/{event}/click`, which
 * the app has to call anyway to learn the URL, so routing it here as well
 * would only ever double it.
 */
class ClientActivityRecorder
{
    /**
     * How far back a client-stamped timestamp may reach. Long enough for a
     * buffer that sat through a weekend offline; short enough that a clock
     * set wrong on the handset cannot backdate rows into last quarter.
     */
    public const MAX_AGE_DAYS = 7;

    public function __construct(
        private readonly RequestFingerprint $fingerprint,
    ) {}

    /**
     * @param  list<ClientActivityEvent>  $events
     */
    public function record(User $user, array $events): ActivityBatchResult
    {
        if ($events === []) {
            return new ActivityBatchResult(0, 0, 0);
        }

        // First occurrence of an id wins, which is also what the unique index
        // would decide between two batches.
        $unique = [];

        foreach ($events as $event) {
            $unique[$event->id] ??= $event;
        }

        $canonical = $this->canonicalEventIds($unique);
        $now = now()->toImmutable();
        $floor = $now->subDays(self::MAX_AGE_DAYS);
        $botReason = $this->fingerprint->botReason();
        $rows = [];
        $dropped = [];

        foreach ($unique as $event) {
            $eventId = $event->eventId;

            if ($eventId !== null) {
                $eventId = $canonical[$eventId] ?? null;

                if ($eventId === null) {
                    $dropped[] = $event->eventId;

                    continue;
                }
            }

            $context = [...$event->context, 'reported_by' => 'client'];

            if ($botReason !== null) {
                $context['bot_reason'] = $botReason;
            }

            $rows[] = [
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'event_id' => $eventId,
                'notification_id' => null,
                'client_event_id' => $event->id,
                'type' => $event->type->value,
                'surface' => $event->surface->value,
                'session_key' => null,
                'is_bot' => $botReason !== null,
                'context' => json_encode($context, JSON_THROW_ON_ERROR),
                // The moment it happened, not the moment the buffer flushed:
                // a basement with no signal must not turn Friday's impressions
                // into Monday's. Clamped, so a wrong clock stays harmless.
                'created_at' => $event->occurredAt->max($floor)->min($now),
                'updated_at' => $now,
            ];
        }

        // The only unique constraints on the table are the primary key (a
        // fresh uuid per row) and (user_id, client_event_id), so an ignored
        // conflict can only ever be a replayed id.
        $accepted = $rows === [] ? 0 : UserActivityLog::insertOrIgnore($rows);

        if ($dropped !== []) {
            // A dropped item is invisible to the app beyond a count, and a
            // client mapping bug would otherwise show up only as a dashboard
            // that has quietly gone flat. Loud here, once per batch.
            Log::warning('ClientActivityRecorder: dropped items naming unknown or hidden events', [
                'user_id' => $user->id,
                'event_ids' => $dropped,
                'batch' => count($events),
                'accepted' => $accepted,
            ]);
        }

        return new ActivityBatchResult(
            accepted: $accepted,
            duplicates: count($events) - count($dropped) - $accepted,
            dropped: count($dropped),
        );
    }

    /**
     * Map each reported event id to the id its row should carry: the
     * canonical event when the reported one was merged into a twin (merged
     * duplicates stay in the table, so a bare existence check would file the
     * impression under the stale copy while the click lands on the canonical
     * one), and nothing at all when it is unknown or hidden — the web 404s
     * hidden events, so an impression of one is not something to count.
     *
     * @param  array<string, ClientActivityEvent>  $events
     * @return array<string, string>
     */
    private function canonicalEventIds(array $events): array
    {
        $ids = [];

        foreach ($events as $event) {
            if ($event->eventId !== null) {
                $ids[$event->eventId] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        $map = [];

        foreach (Event::query()->whereIn('id', array_keys($ids))->get() as $event) {
            $canonical = $event->resolveCanonical();

            if (! $canonical->is_hidden) {
                $map[$event->id] = $canonical->id;
            }
        }

        return $map;
    }
}
