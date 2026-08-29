<?php

declare(strict_types=1);

use App\Enums\ActivitySurface;
use App\Enums\ActivityType;
use App\Enums\Reaction;
use App\Jobs\ProcessFeedbackJob;
use App\Jobs\ReverseProfileDeltaJob;
use App\Models\Event;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Models\UserEventReaction;
use App\Services\Feedback\ReactionRecorder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    $this->recorder = app(ReactionRecorder::class);
    $this->user = User::factory()->create();
    $this->event = Event::factory()->create();
});

it('records a reaction and queues it for scoring', function () {
    $row = $this->recorder->record($this->user, $this->event->id, Reaction::Interested);

    expect($row->reaction)->toBe(Reaction::Interested)
        ->and($row->is_processed)->toBeFalse();

    Queue::assertPushed(ProcessFeedbackJob::class, 1);
});

it('logs the reaction as activity', function () {
    $this->recorder->record($this->user, $this->event->id, Reaction::Interested);

    $log = UserActivityLog::sole();

    expect($log->type)->toBe(ActivityType::ReactionInterested)
        ->and($log->user_id)->toBe($this->user->id)
        ->and($log->event_id)->toBe($this->event->id);
});

it('labels a reaction with the surface the caller names', function () {
    $this->recorder->record($this->user, $this->event->id, Reaction::Interested, ActivitySurface::Digest);

    expect(UserActivityLog::sole()->surface)->toBe(ActivitySurface::Digest);
});

it('treats re-submitting the same reaction as a no-op', function () {
    $this->recorder->record($this->user, $this->event->id, Reaction::Interested);
    UserEventReaction::query()->update(['is_processed' => true]);
    UserActivityLog::query()->delete();

    $this->recorder->record($this->user, $this->event->id, Reaction::Interested);

    // No second job, and no second row in the timeline for a click that
    // changed nothing.
    Queue::assertPushed(ProcessFeedbackJob::class, 1);
    expect(UserActivityLog::count())->toBe(0);
});

it('logs a change of mind as a new activity row', function () {
    $this->recorder->record($this->user, $this->event->id, Reaction::Interested);
    $this->recorder->record($this->user, $this->event->id, Reaction::NotInterested);

    expect(UserActivityLog::ofType(ActivityType::ReactionNotInterested)->count())->toBe(1)
        ->and(UserEventReaction::sole()->reaction)->toBe(Reaction::NotInterested);
});

it('re-queues an unchanged reaction whose job never completed', function () {
    $this->recorder->record($this->user, $this->event->id, Reaction::Interested);
    $this->recorder->record($this->user, $this->event->id, Reaction::Interested);

    // is_processed is still false, so the row is orphaned and must be retried.
    Queue::assertPushed(ProcessFeedbackJob::class, 2);
});

it('logs a cleared reaction and reverses its delta', function () {
    $this->recorder->record($this->user, $this->event->id, Reaction::Interested);
    UserEventReaction::query()->update([
        'is_processed' => true,
        'applied_deltas' => ['music' => 0.15],
    ]);

    $this->recorder->remove($this->user, $this->event->id);

    expect(UserEventReaction::count())->toBe(0)
        ->and(UserActivityLog::ofType(ActivityType::ReactionCleared)->count())->toBe(1);

    Queue::assertPushed(ReverseProfileDeltaJob::class, 1);
});

it('does not queue a reversal when there is no ledger to undo', function () {
    $this->recorder->record($this->user, $this->event->id, Reaction::Interested);

    $this->recorder->remove($this->user, $this->event->id);

    Queue::assertNotPushed(ReverseProfileDeltaJob::class);
});
