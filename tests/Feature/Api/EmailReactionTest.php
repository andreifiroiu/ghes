<?php

declare(strict_types=1);

use App\Enums\Reaction;
use App\Jobs\ProcessFeedbackJob;
use App\Models\Event;
use App\Models\User;
use App\Models\UserEventReaction;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->withoutVite();
});

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

    // The reader is handed the event itself, not a dead-end card.
    $response->assertRedirect();
    expect($response->headers->get('Location'))
        ->toContain("/events/{$event->id}")
        ->toContain('reacted=interested')
        ->toContain('from=digest');

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

    confirmEmailReaction($this, $url)->assertRedirect();

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

it('names the action in Romanian rather than the raw enum value', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    $url = URL::signedRoute('reactions.email', [
        'user' => $user->id,
        'event' => $event->id,
        'reaction' => 'not_interested',
    ]);

    // The interstitial is the only page in this flow now, so it is where the
    // label has to read as Romanian.
    $response = $this->get($url);

    $response->assertSee('Nu-i pentru mine');
    $response->assertDontSee('not interested');
});

it('confirms the reaction on the event page the reader lands on', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create(['starts_at' => now()->addDay()]);

    $url = URL::signedRoute('reactions.email', [
        'user' => $user->id,
        'event' => $event->id,
        'reaction' => 'interested',
    ]);

    // Deliberately not acting as $user: a mail webview usually has no session,
    // which is why the confirmation travels in the URL rather than the flash.
    $landing = confirmEmailReaction($this, $url)->headers->get('Location');

    $this->get($landing)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Events/Show')
            ->where('event.id', $event->id)
            ->where('reactionNotice', 'Am notat — te interesează. Îți vom recomanda evenimente similare.'));
});

it('confirms a saved link with its own wording', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create(['starts_at' => now()->addDay()]);

    $url = URL::signedRoute('reactions.email', [
        'user' => $user->id,
        'event' => $event->id,
        'reaction' => 'saved',
    ]);

    $landing = confirmEmailReaction($this, $url)->headers->get('Location');

    $this->get($landing)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reactionNotice', 'Evenimentul a fost salvat în lista ta.'));
});

it('shows no notice on an ordinary event page visit', function () {
    $event = Event::factory()->create(['starts_at' => now()->addDay()]);

    $this->get("/events/{$event->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('reactionNotice', null));
});

it('ignores a reacted parameter it does not recognise', function () {
    // `?reacted=` is public and anyone can shape it, including as an array —
    // neither form may 500 the page or invent a confirmation.
    $event = Event::factory()->create(['starts_at' => now()->addDay()]);

    foreach (['reacted=nonsense', 'reacted[]=interested'] as $query) {
        $this->get("/events/{$event->id}?{$query}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('reactionNotice', null));
    }
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
