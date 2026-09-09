<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Enums\NotificationChannel;
use App\Enums\NotificationFrequency;
use App\Enums\NotificationType;
use App\Enums\Reaction;
use App\Models\Event;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Composes reminders for events that are about to start.
 *
 * Due-ness here is a property of the *event's* clock, not the user's cadence,
 * which is why this is a sibling of NotificationComposer rather than a branch
 * inside it: nothing about a user's daily/weekly digest preference says
 * anything about when a Saturday gig starts.
 */
class ReminderComposer
{
    /**
     * Compose every reminder now due, across all configured lead tiers.
     *
     * @return Collection<int, Notification>
     */
    public function composeDue(?User $only = null): Collection
    {
        if (! (bool) config('eventpulse.reminders.enabled', true)) {
            return collect();
        }

        /** @var list<int> $leads */
        $leads = array_map(intval(...), (array) config('eventpulse.reminders.lead_options', []));

        $composed = collect();

        foreach (array_unique($leads) as $leadMinutes) {
            $composed = $composed->concat($this->composeForLead($leadMinutes, $only));
        }

        Log::info("Composed {$composed->count()} event reminders");

        return $composed;
    }

    /**
     * Compose the reminders due at one lead tier.
     *
     * @return Collection<int, Notification>
     */
    public function composeForLead(int $leadMinutes, ?User $only = null): Collection
    {
        $window = (int) config('eventpulse.reminders.window_minutes', 30);
        $now = now();

        // upcoming() as well as the window: an event whose start was corrected
        // backwards by a scraper can land inside the window while already being
        // in the past, and "your event starts in 3 hours" about something that
        // finished is the worst thing this feature can send.
        $eventIds = Event::query()
            ->visible()
            ->canonical()
            ->upcoming()
            ->whereBetween('starts_at', [
                $now->copy()->addMinutes($leadMinutes - $window),
                $now->copy()->addMinutes($leadMinutes),
            ])
            ->pluck('id')
            ->all();

        if ($eventIds === []) {
            return collect();
        }

        $audience = $this->audienceFor($eventIds, $leadMinutes, $only);

        if ($audience->isEmpty()) {
            return collect();
        }

        /** @var Collection<string, User> $users */
        $users = User::query()
            ->whereIn('id', $audience->pluck('user_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $rows = $this->rowsFor($audience, $users, $leadMinutes, $now);

        if ($rows === []) {
            return collect();
        }

        // insertOrIgnore, not create(): overlapping runs and a retried job both
        // end at this insert, and the unique index turns the loser into a
        // no-op. A create() loop would raise a QueryException there instead,
        // which the caller cannot tell apart from a real compose failure.
        foreach (array_chunk($rows, 500) as $chunk) {
            Notification::query()->insertOrIgnore($chunk);
        }

        // Read back by the ids this run generated, so rows a concurrent run
        // inserted first are not handed to us to queue a second time.
        return Notification::query()
            ->whereIn('id', array_column($rows, 'id'))
            ->whereNull('sent_at')
            ->get();
    }

    /**
     * Everyone owed a reminder at this lead tier, as (user_id, event_id) pairs.
     *
     * One statement, three deliberate properties:
     *  - the union (not unionAll) collapses a user who both saved an event and
     *    marked it Interested, so one show is one reminder rather than two;
     *  - the join to users filters the audience in SQL, so a run touches rows
     *    proportional to what starts in the window, never to the user table;
     *  - whereNotExists is a cheap pre-filter only. Two overlapping runs both
     *    pass it; the unique index is what actually guarantees at-most-once.
     *
     * @param  list<string>  $eventIds
     * @return Collection<int, object{user_id: string, event_id: string}>
     */
    private function audienceFor(array $eventIds, int $leadMinutes, ?User $only): Collection
    {
        $saved = DB::table('event_bookmarks')
            ->select('user_id', 'event_id')
            ->whereIn('event_id', $eventIds);

        $interested = DB::table('user_event_reactions')
            ->select('user_id', 'event_id')
            ->whereIn('event_id', $eventIds)
            ->where('reaction', Reaction::Interested->value);

        if ($only !== null) {
            $saved->where('user_id', $only->id);
            $interested->where('user_id', $only->id);
        }

        /** @var Collection<int, object{user_id: string, event_id: string}> $audience */
        $audience = DB::query()
            ->fromSub($saved->union($interested), 'aud')
            ->join('users', 'users.id', '=', 'aud.user_id')
            ->where('users.event_reminders_enabled', true)
            // A push-only account has no address to bounce, so it is exempt.
            // Everyone who would be *mailed* must have confirmed the address:
            // reminders multiply mail volume several-fold over the digest, and
            // unverified typo addresses are how a sending domain's reputation
            // goes.
            ->where(function (Builder $query): void {
                $query->where('users.notification_channel', NotificationChannel::Push->value)
                    ->orWhereNotNull('users.email_verified_at');
            })
            ->whereNotExists(function (Builder $query) use ($leadMinutes): void {
                $query->select(DB::raw(1))
                    ->from('event_notifications as en')
                    ->whereColumn('en.user_id', 'aud.user_id')
                    ->whereColumn('en.event_id', 'aud.event_id')
                    ->where('en.lead_minutes', $leadMinutes);
            })
            ->select('aud.user_id', 'aud.event_id')
            ->orderBy('aud.user_id')
            ->limit((int) config('eventpulse.reminders.max_per_run', 5000))
            ->get();

        return $audience;
    }

    /**
     * Turn the audience into insertable rows, dropping users who have not asked
     * for this tier and capping how many one user gets from a single run.
     *
     * Tier membership is decided here rather than in SQL on purpose: it lives
     * in a JSON column, and a whereJsonContains would run through SQLite's
     * JSON1 extension in tests while running through jsonb in production —
     * exactly the shape of query this codebase cannot trust a green suite about.
     *
     * @param  Collection<int, object{user_id: string, event_id: string}>  $audience
     * @param  Collection<string, User>  $users
     * @return list<array<string, mixed>>
     */
    private function rowsFor(Collection $audience, Collection $users, int $leadMinutes, \DateTimeInterface $now): array
    {
        $perUserCap = (int) config('eventpulse.reminders.max_per_user_per_run', 3);
        $perUserCount = [];
        $rows = [];

        foreach ($audience as $pair) {
            $user = $users->get($pair->user_id);

            if ($user === null || ! in_array($leadMinutes, $user->reminderLeadMinutes(), true)) {
                continue;
            }

            $sent = $perUserCount[$user->id] ?? 0;

            if ($sent >= $perUserCap) {
                continue;
            }

            $perUserCount[$user->id] = $sent + 1;

            $rows[] = [
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'type' => NotificationType::Reminder->value,
                'event_id' => $pair->event_id,
                'lead_minutes' => $leadMinutes,
                'channel' => ($user->notification_channel ?? NotificationChannel::Email)->value,
                'frequency' => ($user->notification_frequency ?? NotificationFrequency::Daily)->value,
                // The event goes in the JSON array as well as the FK, so the
                // email renderer, the open pixel and the impression logging all
                // keep working without a per-type branch.
                'event_ids' => json_encode([$pair->event_id]),
                'discovery_event_ids' => '[]',
                // Composed without a subject: it names the time left until the
                // event, so it has to be built when the mail is actually sent.
                'subject' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }
}
