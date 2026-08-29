<?php

declare(strict_types=1);

use App\Enums\ActivitySurface;
use App\Enums\ActivityType;
use App\Enums\Reaction;
use App\Jobs\ProcessBookmarkJob;
use App\Jobs\ReverseProfileDeltaJob;
use App\Models\Event;
use App\Models\EventBookmark;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Models\UserEventReaction;
use App\Services\Bookmarks\BookmarkService;
use App\Services\Feedback\ReactionRecorder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    $this->bookmarks = app(BookmarkService::class);
    $this->user = User::factory()->create();
    $this->event = Event::factory()->create();
});

it('saves an event and queues it for scoring', function () {
    $bookmark = $this->bookmarks->add($this->user, $this->event->id);

    // Asserted on the stored row, not the returned instance: firstOrCreate
    // passes no default for is_processed, so the column takes the database
    // default and the in-memory model does not carry it until reloaded.
    expect($bookmark->fresh()->is_processed)->toBeFalse();
    Queue::assertPushed(ProcessBookmarkJob::class, 1);
});

it('logs the save as activity', function () {
    $this->bookmarks->add($this->user, $this->event->id);

    $log = UserActivityLog::sole();

    expect($log->type)->toBe(ActivityType::BookmarkAdded)
        ->and($log->event_id)->toBe($this->event->id);
});

it('labels a save with the surface the caller names', function () {
    $this->bookmarks->add($this->user, $this->event->id, ActivitySurface::Digest);

    expect(UserActivityLog::sole()->surface)->toBe(ActivitySurface::Digest);
});

it('does not log a second row for an idempotent re-save', function () {
    $this->bookmarks->add($this->user, $this->event->id);
    EventBookmark::query()->update(['is_processed' => true]);

    $this->bookmarks->add($this->user, $this->event->id);

    expect(EventBookmark::count())->toBe(1)
        ->and(UserActivityLog::ofType(ActivityType::BookmarkAdded)->count())->toBe(1);
});

it('re-queues a bookmark whose job never completed', function () {
    $this->bookmarks->add($this->user, $this->event->id);
    $this->bookmarks->add($this->user, $this->event->id);

    Queue::assertPushed(ProcessBookmarkJob::class, 2);
});

it('logs an unsave and reverses its delta', function () {
    $this->bookmarks->add($this->user, $this->event->id);
    EventBookmark::query()->update([
        'is_processed' => true,
        'applied_deltas' => ['music' => 0.2],
    ]);

    $this->bookmarks->remove($this->user, $this->event->id);

    expect(EventBookmark::count())->toBe(0)
        ->and(UserActivityLog::ofType(ActivityType::BookmarkRemoved)->count())->toBe(1);

    Queue::assertPushed(ReverseProfileDeltaJob::class, 1);
});

it('leaves a reaction on the same event untouched', function () {
    app(ReactionRecorder::class)->record($this->user, $this->event->id, Reaction::Interested);
    $this->bookmarks->add($this->user, $this->event->id);

    $this->bookmarks->remove($this->user, $this->event->id);

    // Saving and having an opinion are independent signals.
    expect(UserEventReaction::sole()->reaction)->toBe(Reaction::Interested);
});
