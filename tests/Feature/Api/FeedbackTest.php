<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\User;
use App\Models\UserEventReaction;

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
