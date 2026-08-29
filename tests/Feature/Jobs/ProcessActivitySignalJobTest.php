<?php

declare(strict_types=1);

use App\Enums\ActivityType;
use App\Enums\EventCategory;
use App\Enums\Reaction;
use App\Jobs\ProcessActivitySignalJob;
use App\Models\DiscoveryLog;
use App\Models\Event;
use App\Models\EventSource;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\Feedback\ReactionRecorder;
use App\Services\Recommendation\FeedbackProcessor;

function logClick(User $user, Event $event): UserActivityLog
{
    return UserActivityLog::factory()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'type' => ActivityType::EventClick,
    ]);
}

function runSignalJob(UserActivityLog $log): void
{
    (new ProcessActivitySignalJob($log->id, (string) $log->user_id))
        ->handle(app(FeedbackProcessor::class));
}

beforeEach(function () {
    $this->user = User::factory()->create(['interest_profile' => []]);
    $this->event = Event::factory()->create([
        'category' => EventCategory::Music,
        'tags' => ['jazz'],
    ]);
});

it('nudges the profile by the configured click delta', function () {
    runSignalJob(logClick($this->user, $this->event));

    $profile = $this->user->fresh()->interest_profile;

    expect($profile['music'])->toBe(0.05)
        ->and($profile['tag:jazz'])->toBe(0.05);
});

it('records the applied deltas on the activity row', function () {
    $log = logClick($this->user, $this->event);

    runSignalJob($log);

    // A click has no row of its own to hold a ledger and no way for the user to
    // take it back, so the ledger lives here. It carries the source key too: a
    // click credits the provider that listed the event, the same way an
    // explicit reaction does, just more weakly.
    // Canonicalizing: the ledger lives in a jsonb column, whose key order
    // Postgres normalises and sqlite does not.
    expect($log->fresh()->context['applied_deltas'])->toEqualCanonicalizing([
        'music' => 0.05,
        'tag:jazz' => 0.05,
        "source:{$this->event->source}" => 0.02,
    ]);
});

it('credits every provider that reported the clicked event', function () {
    EventSource::factory()->forSource('iabilet')->create(['event_id' => $this->event->id]);
    EventSource::factory()->forSource('allevents')->create(['event_id' => $this->event->id]);

    runSignalJob(logClick($this->user, $this->event));

    $profile = $this->user->fresh()->interest_profile;

    // Source affinity is learned from explicit reactions; a click is the same
    // signal at a third of the strength, and must not skip the dimension.
    expect($profile['source:iabilet'])->toBe(0.02)
        ->and($profile['source:allevents'])->toBe(0.02);
});

it('scores only the first click on an event', function () {
    runSignalJob(logClick($this->user, $this->event));
    runSignalJob(logClick($this->user, $this->event));
    runSignalJob(logClick($this->user, $this->event));

    // Otherwise re-opening a ticket page is the loudest signal in the system.
    expect($this->user->fresh()->interest_profile['music'])->toBe(0.05);
});

it('scores clicks on different events independently', function () {
    $other = Event::factory()->create(['category' => EventCategory::Music, 'tags' => []]);

    runSignalJob(logClick($this->user, $this->event));
    runSignalJob(logClick($this->user, $other));

    expect($this->user->fresh()->interest_profile['music'])->toBe(0.1);
});

it('does not let an earlier bot click block a real one from scoring', function () {
    UserActivityLog::factory()->bot()->create([
        'user_id' => $this->user->id,
        'event_id' => $this->event->id,
        'type' => ActivityType::EventClick,
    ]);

    runSignalJob(logClick($this->user, $this->event));

    expect($this->user->fresh()->interest_profile['music'])->toBe(0.05);
});

it('applies the discovery reward multiplier to a click on a discovery event', function () {
    DiscoveryLog::factory()->create([
        'user_id' => $this->user->id,
        'event_id' => $this->event->id,
        'category_explored' => EventCategory::Music->value,
        'outcome' => null,
    ]);

    runSignalJob(logClick($this->user, $this->event));

    // 0.05 × discovery.reward_multiplier (1.5)
    expect($this->user->fresh()->interest_profile['music'])->toEqualWithDelta(0.075, 0.0001);
});

it('resolves an unreacted discovery as a click', function () {
    $discovery = DiscoveryLog::factory()->create([
        'user_id' => $this->user->id,
        'event_id' => $this->event->id,
        'outcome' => null,
    ]);

    runSignalJob(logClick($this->user, $this->event));

    expect($discovery->fresh()->outcome)->toBe('clicked');
});

it('lets an explicit reaction override a click on the same discovery', function () {
    $discovery = DiscoveryLog::factory()->create([
        'user_id' => $this->user->id,
        'event_id' => $this->event->id,
        'outcome' => null,
    ]);

    runSignalJob(logClick($this->user, $this->event));
    expect($discovery->fresh()->outcome)->toBe('clicked');

    // Clicking through and then saying "not for me" means the exploration
    // missed, and that answer has to survive.
    app(ReactionRecorder::class)->record($this->user, $this->event->id, Reaction::NotInterested);
    app(FeedbackProcessor::class)->processUnprocessed();

    expect($discovery->fresh()->outcome)->toBe('not_interested');
});

it('does nothing when the activity row has gone', function () {
    $log = logClick($this->user, $this->event);
    $userId = (string) $log->user_id;
    $log->delete();

    (new ProcessActivitySignalJob($log->id, $userId))
        ->handle(app(FeedbackProcessor::class));

    expect($this->user->fresh()->interest_profile)->toBe([]);
});

it('does nothing for a click with no user', function () {
    $log = UserActivityLog::factory()->guest()->create([
        'event_id' => $this->event->id,
        'type' => ActivityType::EventClick,
    ]);

    (new ProcessActivitySignalJob($log->id, (string) $this->user->id))
        ->handle(app(FeedbackProcessor::class));

    expect($log->fresh()->context)->not->toHaveKey('applied_deltas');
});

it('does not lock out a future click when nothing could be applied', function () {
    // Every key already clamped at the ceiling, so apply() returns [].
    $this->user->update(['interest_profile' => ['music' => 1.0, 'tag:jazz' => 1.0, "source:{$this->event->source}" => 1.0]]);

    runSignalJob(logClick($this->user, $this->event));

    // An empty ledger must not count as "scored", or once a user's scores touch
    // 1.0 no later click on that event could ever contribute again — not even
    // after ProfileDecayer pulls them back down.
    $this->user->update(['interest_profile' => ['music' => 0.5, 'tag:jazz' => 0.5]]);

    runSignalJob(logClick($this->user, $this->event));

    expect($this->user->fresh()->interest_profile['music'])->toBe(0.55);
});

it('does not double-apply when the queue redelivers the same job', function () {
    $log = logClick($this->user, $this->event);

    // Queues are at-least-once: a worker killed after the transaction commits
    // but before the ack replays this exact payload. The sibling guard cannot
    // catch it — it excludes the row being processed — so the row has to check
    // its own ledger, or the delta lands twice while the ledger records once
    // and the excess can never be reversed.
    runSignalJob($log);
    runSignalJob($log);
    runSignalJob($log);

    $profile = $this->user->fresh()->interest_profile;

    expect($profile['music'])->toBe(0.05)
        ->and($profile['tag:jazz'])->toBe(0.05)
        ->and($log->fresh()->context['applied_deltas']['music'])->toBe(0.05);
});
