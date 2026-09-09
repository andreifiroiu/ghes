<?php

declare(strict_types=1);

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\Event;
use App\Models\EventBookmark;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserEventReaction;
use App\Services\Notification\ReminderComposer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    // One tier, so a test that means "did this compose" is not silently
    // answered by a second tier it never mentioned.
    config([
        'eventpulse.reminders.enabled' => true,
        'eventpulse.reminders.lead_options' => [180],
        'eventpulse.reminders.default_lead_minutes' => [180],
        'eventpulse.reminders.window_minutes' => 30,
        'eventpulse.reminders.max_per_user_per_run' => 3,
    ]);
});

function reminderComposer(): ReminderComposer
{
    return app(ReminderComposer::class);
}

/**
 * A user who can actually be mailed. UserFactory randomises the channel, and a
 * push-only account skips the verified-address guard, so every test that is not
 * about the channel has to pin it.
 */
function reminderUser(array $attributes = []): User
{
    return User::factory()->create([
        'notification_channel' => NotificationChannel::Email,
        'email_verified_at' => now(),
        ...$attributes,
    ]);
}

function eventStartingIn(int $minutes): Event
{
    return Event::withoutSyncingToSearch(
        fn (): Event => Event::factory()->startingIn($minutes)->create(),
    );
}

it('composes a reminder for an event the user bookmarked', function () {
    $user = reminderUser();
    $event = eventStartingIn(175);
    EventBookmark::factory()->create(['user_id' => $user->id, 'event_id' => $event->id]);

    $composed = reminderComposer()->composeDue();

    expect($composed)->toHaveCount(1);

    $reminder = $composed->first();
    expect($reminder->type)->toBe(NotificationType::Reminder)
        ->and($reminder->event_id)->toBe($event->id)
        ->and($reminder->lead_minutes)->toBe(180)
        // The event id lands in the JSON array too, so the renderer, the open
        // pixel and the impression logging need no per-type branch.
        ->and($reminder->event_ids)->toBe([$event->id])
        ->and($reminder->sent_at)->toBeNull();
});

it('composes a reminder for an event the user marked interested', function () {
    $user = reminderUser();
    $event = eventStartingIn(175);
    UserEventReaction::factory()->interested()->create(['user_id' => $user->id, 'event_id' => $event->id]);

    expect(reminderComposer()->composeDue())->toHaveCount(1);
});

/**
 * Saving and having an opinion are independent actions on one event, so a user
 * who did both is in the audience twice. Two reminders about one gig is the
 * most obviously wrong thing this feature could do.
 */
it('composes exactly one reminder for a user who both saved and marked interested', function () {
    $user = reminderUser();
    $event = eventStartingIn(175);
    EventBookmark::factory()->create(['user_id' => $user->id, 'event_id' => $event->id]);
    UserEventReaction::factory()->interested()->create(['user_id' => $user->id, 'event_id' => $event->id]);

    expect(reminderComposer()->composeDue())->toHaveCount(1);
});

it('composes nothing for a not-interested reaction', function () {
    $user = reminderUser();
    $event = eventStartingIn(175);
    UserEventReaction::factory()->notInterested()->create(['user_id' => $user->id, 'event_id' => $event->id]);

    expect(reminderComposer()->composeDue())->toBeEmpty();
});

it('ignores events on either side of the window', function (int $startsInMinutes) {
    $user = reminderUser();
    $event = eventStartingIn($startsInMinutes);
    EventBookmark::factory()->create(['user_id' => $user->id, 'event_id' => $event->id]);

    expect(reminderComposer()->composeDue())->toBeEmpty();
})->with([
    'too soon — already past the 3h tier' => [120],
    'too far — the tier has not come round yet' => [240],
]);

it('ignores hidden, merged and already-started events', function (string $case) {
    $user = reminderUser();

    $event = Event::withoutSyncingToSearch(function () use ($case): Event {
        $event = Event::factory()->startingIn(175)->create();

        if ($case === 'hidden') {
            $event->forceFill(['is_hidden' => true])->save();
        }

        if ($case === 'merged') {
            $canonical = Event::factory()->startingIn(175)->create();
            $event->forceFill(['merged_into_id' => $canonical->id])->save();
        }

        if ($case === 'started') {
            // Inside the window by clock arithmetic, but in the past: a scraper
            // correcting starts_at backwards can produce exactly this.
            $event->forceFill(['starts_at' => now()->subMinutes(10)])->save();
        }

        return $event;
    });

    EventBookmark::factory()->create(['user_id' => $user->id, 'event_id' => $event->id]);

    expect(reminderComposer()->composeDue())->toBeEmpty();
})->with(['hidden', 'merged', 'started']);

it('skips a user who turned reminders off', function () {
    $user = reminderUser(['event_reminders_enabled' => false]);
    $event = eventStartingIn(175);
    EventBookmark::factory()->create(['user_id' => $user->id, 'event_id' => $event->id]);

    expect(reminderComposer()->composeDue())->toBeEmpty();
});

/**
 * Reminders multiply mail volume several-fold over the digest, so an
 * unconfirmed address is a deliverability risk rather than an inconvenience.
 * A push-only account has no address to bounce and is exempt.
 */
it('skips an unverified email-channel user but not an unverified push-only one', function () {
    $emailUser = reminderUser(['email_verified_at' => null]);
    $pushUser = reminderUser([
        'notification_channel' => NotificationChannel::Push,
        'email_verified_at' => null,
    ]);
    $event = eventStartingIn(175);

    EventBookmark::factory()->create(['user_id' => $emailUser->id, 'event_id' => $event->id]);
    EventBookmark::factory()->create(['user_id' => $pushUser->id, 'event_id' => $event->id]);

    $composed = reminderComposer()->composeDue();

    expect($composed)->toHaveCount(1)
        ->and($composed->first()->user_id)->toBe($pushUser->id);
});

it('composes one row per lead tier the user has chosen', function () {
    config(['eventpulse.reminders.lead_options' => [1440, 180]]);

    $user = reminderUser(['reminder_lead_minutes' => [1440, 180]]);
    $soon = eventStartingIn(175);
    $tomorrow = eventStartingIn(1435);

    EventBookmark::factory()->create(['user_id' => $user->id, 'event_id' => $soon->id]);
    EventBookmark::factory()->create(['user_id' => $user->id, 'event_id' => $tomorrow->id]);

    $composed = reminderComposer()->composeDue();

    expect($composed->pluck('lead_minutes')->sort()->values()->all())->toBe([180, 1440]);
});

it('does not compose a tier the user deselected', function () {
    config(['eventpulse.reminders.lead_options' => [1440, 180]]);

    $user = reminderUser(['reminder_lead_minutes' => [1440]]);
    $event = eventStartingIn(175);
    EventBookmark::factory()->create(['user_id' => $user->id, 'event_id' => $event->id]);

    expect(reminderComposer()->composeDue())->toBeEmpty();
});

it('falls back to the configured default tiers when the user has never chosen', function () {
    config([
        'eventpulse.reminders.lead_options' => [1440, 180],
        'eventpulse.reminders.default_lead_minutes' => [1440],
    ]);

    $user = reminderUser(['reminder_lead_minutes' => null]);
    $soon = eventStartingIn(175);
    $tomorrow = eventStartingIn(1435);

    EventBookmark::factory()->create(['user_id' => $user->id, 'event_id' => $soon->id]);
    EventBookmark::factory()->create(['user_id' => $user->id, 'event_id' => $tomorrow->id]);

    $composed = reminderComposer()->composeDue();

    expect($composed)->toHaveCount(1)
        ->and($composed->first()->lead_minutes)->toBe(1440);
});

/**
 * The load-bearing one. Consecutive scheduler runs overlap by design — the
 * window is wider than the tick — so the second run sees the same event and the
 * same audience, and only the unique index stops a duplicate email.
 */
it('inserts nothing on a second run over the same window', function () {
    $user = reminderUser();
    $event = eventStartingIn(175);
    EventBookmark::factory()->create(['user_id' => $user->id, 'event_id' => $event->id]);

    reminderComposer()->composeDue();
    $again = reminderComposer()->composeDue();

    expect($again)->toBeEmpty()
        ->and(Notification::query()->reminders()->count())->toBe(1);
});

/**
 * A run must return only the rows *it* wrote. Returning rows another run had
 * already inserted would queue a second SendNotificationJob for them, and the
 * sent_at guard only helps once the first job has finished.
 */
it('returns nothing for rows a concurrent run already inserted', function () {
    $user = reminderUser();
    $event = eventStartingIn(175);
    EventBookmark::factory()->create(['user_id' => $user->id, 'event_id' => $event->id]);

    // The row exists but has not been sent, exactly as a racing run leaves it.
    Notification::factory()->reminder($event, 180)->create([
        'user_id' => $user->id,
        'sent_at' => null,
    ]);

    expect(reminderComposer()->composeDue())->toBeEmpty()
        ->and(Notification::query()->reminders()->count())->toBe(1);
});

/**
 * The whereNotExists pre-filter is not the guarantee — two runs racing both
 * pass it. This pins the thing that actually holds: the unique index, and that
 * digests (null event_id, null lead_minutes) are not caught by it.
 */
it('rejects a duplicate reminder at the database level but not a second digest', function () {
    $user = reminderUser();
    $event = eventStartingIn(175);

    Notification::factory()->reminder($event, 180)->create(['user_id' => $user->id]);

    expect(fn () => Notification::factory()->reminder($event, 180)->create(['user_id' => $user->id]))
        ->toThrow(QueryException::class);

    // Same tier, different event, and the same event at a different tier are
    // both legitimate rows.
    Notification::factory()->reminder($event, 1440)->create(['user_id' => $user->id]);

    Notification::factory()->count(2)->create(['user_id' => $user->id]);

    expect(Notification::query()->digests()->count())->toBe(2)
        ->and(Notification::query()->reminders()->count())->toBe(2);
});

it('caps how many reminders one user gets from a single run', function () {
    config(['eventpulse.reminders.max_per_user_per_run' => 2]);

    $user = reminderUser();

    foreach (range(1, 5) as $offset) {
        $event = eventStartingIn(160 + $offset);
        EventBookmark::factory()->create(['user_id' => $user->id, 'event_id' => $event->id]);
    }

    expect(reminderComposer()->composeDue())->toHaveCount(2);
});

it('composes nothing when the feature is switched off', function () {
    config(['eventpulse.reminders.enabled' => false]);

    $user = reminderUser();
    $event = eventStartingIn(175);
    EventBookmark::factory()->create(['user_id' => $user->id, 'event_id' => $event->id]);

    expect(reminderComposer()->composeDue())->toBeEmpty();
});

it('scopes to one user when asked', function () {
    $wanted = reminderUser();
    $other = reminderUser();
    $event = eventStartingIn(175);

    EventBookmark::factory()->create(['user_id' => $wanted->id, 'event_id' => $event->id]);
    EventBookmark::factory()->create(['user_id' => $other->id, 'event_id' => $event->id]);

    $composed = reminderComposer()->composeDue($wanted);

    expect($composed)->toHaveCount(1)
        ->and($composed->first()->user_id)->toBe($wanted->id);
});

/**
 * The audience is selected in SQL precisely so a run costs what the window
 * holds, not what the user table holds. A per-row user lookup would pass every
 * other test in this file while making the scheduled run quadratic.
 */
it('does not issue a query per user in the audience', function () {
    $event = eventStartingIn(175);

    foreach (range(1, 20) as $ignored) {
        $user = reminderUser();
        EventBookmark::factory()->create(['user_id' => $user->id, 'event_id' => $event->id]);
    }

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $composed = reminderComposer()->composeDue();

    expect($composed)->toHaveCount(20)
        // events + audience + users + insert + read-back, with room to spare.
        ->and($queries)->toBeLessThan(10);
});
