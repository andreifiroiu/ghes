<?php

declare(strict_types=1);

use App\Enums\Reaction;
use App\Jobs\ProcessFeedbackJob;
use App\Models\Event;
use App\Models\User;
use App\Models\UserEventReaction;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;

/**
 * Follow a signed email link the way a browser does: GET the confirmation page,
 * then POST the same signed URL.
 */
function confirmEmailReaction(object $test, string $url)
{
    $test->get($url)->assertStatus(200);

    return $test->post($url);
}

it('records a reaction from a signed email URL', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    $url = URL::signedRoute('reactions.email', [
        'user' => $user->id,
        'event' => $event->id,
        'reaction' => 'interested',
    ]);

    $response = confirmEmailReaction($this, $url);

    $response->assertStatus(200);
    $response->assertSee('Reacție înregistrată');
    $response->assertSee($event->title);

    $this->assertDatabaseHas('user_event_reactions', [
        'user_id' => $user->id,
        'event_id' => $event->id,
        'reaction' => 'interested',
    ]);
});

it('does not write anything on the GET, so link prefetchers cannot react', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    $url = URL::signedRoute('reactions.email', [
        'user' => $user->id,
        'event' => $event->id,
        'reaction' => 'not_interested',
    ]);

    $this->get($url)->assertStatus(200);

    $this->assertDatabaseCount('user_event_reactions', 0);
    $this->assertDatabaseCount('event_bookmarks', 0);
});

it('rejects unsigned URLs with 403', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    $this->get("/reactions/{$user->id}/{$event->id}/interested")->assertStatus(403);
    $this->post("/reactions/{$user->id}/{$event->id}/interested")->assertStatus(403);
});

it('handles not_interested reaction from email', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    $url = URL::signedRoute('reactions.email', [
        'user' => $user->id,
        'event' => $event->id,
        'reaction' => 'not_interested',
    ]);

    confirmEmailReaction($this, $url);

    $this->assertDatabaseHas('user_event_reactions', [
        'user_id' => $user->id,
        'event_id' => $event->id,
        'reaction' => 'not_interested',
    ]);
});

it('a saved link creates a bookmark, not a reaction', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    $url = URL::signedRoute('reactions.email', [
        'user' => $user->id,
        'event' => $event->id,
        'reaction' => 'saved',
    ]);

    confirmEmailReaction($this, $url);

    $this->assertDatabaseHas('event_bookmarks', [
        'user_id' => $user->id,
        'event_id' => $event->id,
    ]);
    $this->assertDatabaseCount('user_event_reactions', 0);
});

it('maps a legacy hidden link onto not_interested', function () {
    // Signed links live 30 days, so links sent before the negative reactions
    // were collapsed are still in inboxes and must not 404.
    $user = User::factory()->create();
    $event = Event::factory()->create();

    $url = URL::signedRoute('reactions.email', [
        'user' => $user->id,
        'event' => $event->id,
        'reaction' => 'hidden',
    ]);

    confirmEmailReaction($this, $url)->assertStatus(200);

    $this->assertDatabaseHas('user_event_reactions', [
        'user_id' => $user->id,
        'event_id' => $event->id,
        'reaction' => 'not_interested',
    ]);
});

it('returns 404 for invalid reaction types', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    $url = URL::signedRoute('reactions.email', [
        'user' => $user->id,
        'event' => $event->id,
        'reaction' => 'invalid_type',
    ]);

    $this->get($url)->assertStatus(404);
    $this->post($url)->assertStatus(404);
});

it('confirms in Romanian rather than the raw enum value', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    $url = URL::signedRoute('reactions.email', [
        'user' => $user->id,
        'event' => $event->id,
        'reaction' => 'not_interested',
    ]);

    $response = confirmEmailReaction($this, $url);

    $response->assertSee('Nu-i pentru mine');
    $response->assertDontSee('not interested');
});

it('a saved link leaves an existing reaction alone', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    UserEventReaction::create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'reaction' => Reaction::Interested,
    ]);

    $url = URL::signedRoute('reactions.email', [
        'user' => $user->id,
        'event' => $event->id,
        'reaction' => 'saved',
    ]);

    confirmEmailReaction($this, $url);

    expect(
        UserEventReaction::where('user_id', $user->id)
            ->where('event_id', $event->id)
            ->first()
            ->reaction
    )->toBe(Reaction::Interested);

    $this->assertDatabaseHas('event_bookmarks', [
        'user_id' => $user->id,
        'event_id' => $event->id,
    ]);
});

it('dispatches ProcessFeedbackJob on reaction', function () {
    Queue::fake();

    $user = User::factory()->create();
    $event = Event::factory()->create();

    $url = URL::signedRoute('reactions.email', [
        'user' => $user->id,
        'event' => $event->id,
        'reaction' => 'interested',
    ]);

    confirmEmailReaction($this, $url);

    Queue::assertPushed(ProcessFeedbackJob::class);
});
