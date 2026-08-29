<?php

declare(strict_types=1);

use App\Enums\EventCategory;
use App\Jobs\ProcessBookmarkJob;
use App\Jobs\ProcessFeedbackJob;
use App\Models\Event;
use App\Models\EventBookmark;
use App\Models\User;
use App\Models\UserEventReaction;
use App\Services\Recommendation\FeedbackProcessor;
use Illuminate\Support\Facades\Queue;

it('can react to an event', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    $response = $this->actingAs($user)->postJson('/feedback', [
        'event_id' => $event->id,
        'reaction' => 'interested',
    ]);

    $response->assertStatus(200);
    $this->assertDatabaseHas('user_event_reactions', [
        'user_id' => $user->id,
        'event_id' => $event->id,
        'reaction' => 'interested',
    ]);
});

it('validates reaction type', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    $response = $this->actingAs($user)->postJson('/feedback', [
        'event_id' => $event->id,
        'reaction' => 'invalid_reaction',
    ]);

    $response->assertStatus(422);
});

it('validates event exists', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/feedback', [
        'event_id' => '00000000-0000-0000-0000-000000000000',
        'reaction' => 'interested',
    ]);

    $response->assertStatus(422);
});

it('requires authentication to submit feedback', function () {
    $event = Event::factory()->create();

    $response = $this->postJson('/feedback', [
        'event_id' => $event->id,
        'reaction' => 'interested',
    ]);

    $response->assertStatus(401);
});

it('can remove a reaction from an event', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();
    UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'reaction' => 'interested',
    ]);

    $response = $this->actingAs($user)->deleteJson('/feedback', [
        'event_id' => $event->id,
    ]);

    $response->assertStatus(200);
    $this->assertDatabaseMissing('user_event_reactions', [
        'user_id' => $user->id,
        'event_id' => $event->id,
    ]);
});

it('only removes the current user reaction', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $event = Event::factory()->create();
    UserEventReaction::factory()->create([
        'user_id' => $other->id,
        'event_id' => $event->id,
        'reaction' => 'interested',
    ]);

    $this->actingAs($user)->deleteJson('/feedback', [
        'event_id' => $event->id,
    ])->assertStatus(200);

    $this->assertDatabaseHas('user_event_reactions', [
        'user_id' => $other->id,
        'event_id' => $event->id,
    ]);
});

it('requires authentication to remove feedback', function () {
    $event = Event::factory()->create();

    $this->deleteJson('/feedback', [
        'event_id' => $event->id,
    ])->assertStatus(401);
});

it('rejects retired reaction values', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    foreach (['saved', 'hidden', 'link_opened'] as $retired) {
        $this->actingAs($user)->postJson('/feedback', [
            'event_id' => $event->id,
            'reaction' => $retired,
        ])->assertStatus(422);
    }
});

it('re-scores the profile when the reaction changes', function () {
    $user = User::factory()->create(['interest_profile' => []]);
    $event = Event::factory()->create(['category' => EventCategory::Music, 'tags' => []]);

    $this->actingAs($user)->postJson('/feedback', [
        'event_id' => $event->id,
        'reaction' => 'interested',
    ])->assertStatus(200);

    $this->actingAs($user)->postJson('/feedback', [
        'event_id' => $event->id,
        'reaction' => 'not_interested',
    ])->assertStatus(200);

    // Only the not_interested delta should be present — the interested delta
    // must have been reversed rather than stacked on.
    $expected = max(0.0, (float) config('eventpulse.feedback.deltas.not_interested.category'));

    expect($user->fresh()->interest_profile['music'] ?? 0.0)
        ->toEqualWithDelta($expected, 0.0001);
});

it('does not re-dispatch an identical reaction that already processed', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    // Runs inline (sync queue), so the row comes back is_processed = true.
    $this->actingAs($user)->postJson('/feedback', [
        'event_id' => $event->id,
        'reaction' => 'interested',
    ])->assertStatus(200);

    Queue::fake();

    $this->actingAs($user)->postJson('/feedback', [
        'event_id' => $event->id,
        'reaction' => 'interested',
    ])->assertStatus(200);

    Queue::assertNotPushed(ProcessFeedbackJob::class);
});

it('re-dispatches an identical reaction whose job never completed', function () {
    // Nothing sweeps is_processed = false, so clicking the button again has to
    // be able to rescue a row orphaned by a lost or failed job.
    $user = User::factory()->create();
    $event = Event::factory()->create();

    UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'reaction' => 'interested',
        'is_processed' => false,
    ]);

    Queue::fake();

    $this->actingAs($user)->postJson('/feedback', [
        'event_id' => $event->id,
        'reaction' => 'interested',
    ])->assertStatus(200);

    Queue::assertPushed(ProcessFeedbackJob::class, 1);
});

it('undoing a reaction restores the profile', function () {
    $user = User::factory()->create(['interest_profile' => ['music' => 0.3]]);
    $event = Event::factory()->create(['category' => EventCategory::Music, 'tags' => []]);

    $this->actingAs($user)->postJson('/feedback', [
        'event_id' => $event->id,
        'reaction' => 'interested',
    ])->assertStatus(200);

    $this->actingAs($user)->deleteJson('/feedback', [
        'event_id' => $event->id,
    ])->assertStatus(200);

    expect($user->fresh()->interest_profile['music'])->toEqualWithDelta(0.3, 0.0001);
});

it('saving and reacting are independent', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    $this->actingAs($user)->postJson('/feedback', [
        'event_id' => $event->id,
        'reaction' => 'interested',
    ])->assertStatus(200);

    $this->actingAs($user)->postJson('/bookmarks', [
        'event_id' => $event->id,
    ])->assertStatus(200);

    $this->assertDatabaseHas('user_event_reactions', [
        'user_id' => $user->id,
        'event_id' => $event->id,
        'reaction' => 'interested',
    ]);
    $this->assertDatabaseHas('event_bookmarks', [
        'user_id' => $user->id,
        'event_id' => $event->id,
    ]);
});

it('unsaving leaves the reaction intact', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    $this->actingAs($user)->postJson('/feedback', [
        'event_id' => $event->id,
        'reaction' => 'interested',
    ]);
    $this->actingAs($user)->postJson('/bookmarks', ['event_id' => $event->id]);
    $this->actingAs($user)->deleteJson('/bookmarks', ['event_id' => $event->id])
        ->assertStatus(200);

    $this->assertDatabaseMissing('event_bookmarks', [
        'user_id' => $user->id,
        'event_id' => $event->id,
    ]);
    $this->assertDatabaseHas('user_event_reactions', [
        'user_id' => $user->id,
        'event_id' => $event->id,
        'reaction' => 'interested',
    ]);
});

it('un-reacting leaves the bookmark intact', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    $this->actingAs($user)->postJson('/feedback', [
        'event_id' => $event->id,
        'reaction' => 'interested',
    ]);
    $this->actingAs($user)->postJson('/bookmarks', ['event_id' => $event->id]);
    $this->actingAs($user)->deleteJson('/feedback', ['event_id' => $event->id])
        ->assertStatus(200);

    $this->assertDatabaseMissing('user_event_reactions', [
        'user_id' => $user->id,
        'event_id' => $event->id,
    ]);
    $this->assertDatabaseHas('event_bookmarks', [
        'user_id' => $user->id,
        'event_id' => $event->id,
    ]);
});

it('saving twice is idempotent', function () {
    $user = User::factory()->create(['interest_profile' => []]);
    $event = Event::factory()->create(['category' => EventCategory::Music, 'tags' => []]);

    $this->actingAs($user)->postJson('/bookmarks', ['event_id' => $event->id]);
    $this->actingAs($user)->postJson('/bookmarks', ['event_id' => $event->id])
        ->assertStatus(200);

    expect(EventBookmark::where('user_id', $user->id)->count())->toBe(1);
    expect($user->fresh()->interest_profile['music'])
        ->toEqualWithDelta((float) config('eventpulse.feedback.deltas.saved.category'), 0.0001);
});

it('requires authentication to bookmark', function () {
    $event = Event::factory()->create();

    $this->postJson('/bookmarks', ['event_id' => $event->id])->assertStatus(401);
    $this->deleteJson('/bookmarks', ['event_id' => $event->id])->assertStatus(401);
});

it('tolerates the reaction being removed before its job runs', function () {
    // React-then-undo is routine; the job must treat a missing row as a normal
    // outcome rather than burning its retries and landing in failed_jobs.
    $user = User::factory()->create();
    $event = Event::factory()->create();

    $reaction = UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'reaction' => 'interested',
        'is_processed' => false,
    ]);

    $reactionId = $reaction->id;
    $reaction->delete();

    (new ProcessFeedbackJob($reactionId, $user->id))
        ->handle(app(FeedbackProcessor::class));
})->throwsNoExceptions();

it('tolerates the bookmark being removed before its job runs', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    $bookmark = EventBookmark::factory()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'is_processed' => false,
    ]);

    $bookmarkId = $bookmark->id;
    $bookmark->delete();

    (new ProcessBookmarkJob($bookmarkId, $user->id))
        ->handle(app(FeedbackProcessor::class));
})->throwsNoExceptions();
