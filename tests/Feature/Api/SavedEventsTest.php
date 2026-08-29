<?php

declare(strict_types=1);

use App\Enums\Reaction;
use App\Models\Event;
use App\Models\EventBookmark;
use App\Models\User;
use App\Models\UserEventReaction;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->withoutVite();
});

it('shows only the user saved events', function () {
    $user = User::factory()->create();

    $saved = Event::factory()->create(['starts_at' => now()->addDays(2), 'is_classified' => true]);
    $interested = Event::factory()->create(['starts_at' => now()->addDays(2), 'is_classified' => true]);

    EventBookmark::factory()->create([
        'user_id' => $user->id,
        'event_id' => $saved->id,
    ]);
    UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $interested->id,
        'reaction' => Reaction::Interested,
    ]);

    $this->actingAs($user)
        ->get('/events/saved')
        ->assertStatus(200)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard/SavedEvents')
            ->has('events', 1)
            ->where('events.0.id', $saved->id)
        );
});

it('does not show another user saved events', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $event = Event::factory()->create(['starts_at' => now()->addDays(2), 'is_classified' => true]);
    EventBookmark::factory()->create([
        'user_id' => $other->id,
        'event_id' => $event->id,
    ]);

    $this->actingAs($user)
        ->get('/events/saved')
        ->assertStatus(200)
        ->assertInertia(fn (AssertableInertia $page) => $page->has('events', 0));
});

it('requires authentication to view saved events', function () {
    $this->get('/events/saved')->assertRedirect('/login');
});

it('shows an event that is both saved and reacted to', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create(['starts_at' => now()->addDays(2), 'is_classified' => true]);

    UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'reaction' => Reaction::Interested,
    ]);
    EventBookmark::factory()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
    ]);

    $this->actingAs($user)
        ->get('/events/saved')
        ->assertStatus(200)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events', 1)
            ->where('events.0.id', $event->id)
            ->where('events.0.current_reaction', 'interested')
            ->where('events.0.is_saved', true)
        );
});
