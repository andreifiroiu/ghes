<?php

declare(strict_types=1);

use App\Enums\EventCategory;
use App\Enums\Reaction;
use App\Models\Event;
use App\Models\User;
use App\Models\UserEventReaction;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->withoutVite();
});

it('filters events by category using the enum value', function () {
    $user = User::factory()->create();

    $music = Event::factory()->create([
        'category' => EventCategory::Music,
        'starts_at' => now()->addDays(2),
    ]);
    Event::factory()->create([
        'category' => EventCategory::Sports,
        'starts_at' => now()->addDays(2),
    ]);

    $this->actingAs($user)
        ->get('/events?category=music')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Events/Index')
            ->has('events.data', 1)
            ->where('events.data.0.id', $music->id)
            ->where('filters.category', 'music')
        );
});

it('returns all upcoming events when no category filter is applied', function () {
    $user = User::factory()->create();

    Event::factory()->count(3)->create(['starts_at' => now()->addDays(2)]);

    $this->actingAs($user)
        ->get('/events')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Events/Index')
            ->has('events.data', 3)
        );
});

it('filters events to a single selected date in the city timezone', function () {
    $user = User::factory()->create();
    $tz = config('eventpulse.cities.'.config('eventpulse.default_city').'.timezone');

    // Target day, local time -> stored UTC.
    $targetDay = now($tz)->addDays(10)->startOfDay();
    $onDay = Event::factory()->create([
        'starts_at' => $targetDay->copy()->setTime(20, 0)->utc(),
    ]);
    // Next day, just after midnight local time -> must be excluded.
    Event::factory()->create([
        'starts_at' => $targetDay->copy()->addDay()->setTime(0, 30)->utc(),
    ]);

    $date = $targetDay->format('Y-m-d');

    $this->actingAs($user)
        ->get("/events?date={$date}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events.data', 1)
            ->where('events.data.0.id', $onDay->id)
            ->where('filters.date', $date)
        );
});

it('ignores an invalid date and returns all upcoming events', function () {
    $user = User::factory()->create();

    Event::factory()->count(2)->create(['starts_at' => now()->addDays(3)]);

    $this->actingAs($user)
        ->get('/events?date=not-a-date')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('events.data', 2));
});

it('excludes admin-hidden events from the browse list', function () {
    $user = User::factory()->create();

    $visible = Event::factory()->create([
        'starts_at' => now()->addDays(2),
        'is_hidden' => false,
    ]);
    $hidden = Event::factory()->create([
        'starts_at' => now()->addDays(2),
        'is_hidden' => true,
    ]);

    $this->actingAs($user)
        ->get('/events')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events.data', 1)
            ->where('events.data.0.id', $visible->id)
        );

    expect($hidden->fresh()->is_hidden)->toBeTrue();
});

it('returns 404 for an admin-hidden event detail page', function () {
    $user = User::factory()->create();
    $hidden = Event::factory()->create(['is_hidden' => true]);

    $this->actingAs($user)->get("/events/{$hidden->id}")->assertNotFound();
});

it('excludes admin-hidden events from the saved list', function () {
    $user = User::factory()->create();

    $saved = Event::factory()->create(['starts_at' => now()->addDays(2), 'is_hidden' => false]);
    $savedThenHidden = Event::factory()->create(['starts_at' => now()->addDays(2), 'is_hidden' => true]);

    foreach ([$saved, $savedThenHidden] as $event) {
        UserEventReaction::factory()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'reaction' => Reaction::Saved,
        ]);
    }

    $this->actingAs($user)
        ->get('/events/saved')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events', 1)
            ->where('events.0.id', $saved->id)
        );
});

it('excludes events the user marked not-interested or hidden from the browse list', function () {
    $user = User::factory()->create();

    $visible = Event::factory()->create(['starts_at' => now()->addDays(2)]);
    $notInterested = Event::factory()->create(['starts_at' => now()->addDays(2)]);
    $hidden = Event::factory()->create(['starts_at' => now()->addDays(2)]);
    $interested = Event::factory()->create(['starts_at' => now()->addDays(2)]);

    UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $notInterested->id,
        'reaction' => Reaction::NotInterested,
    ]);
    UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $hidden->id,
        'reaction' => Reaction::Hidden,
    ]);
    UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $interested->id,
        'reaction' => Reaction::Interested,
    ]);

    $this->actingAs($user)
        ->get('/events')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events.data', 2)
            ->where('events.data', fn ($events) => collect($events)->pluck('id')->sort()->values()->all()
                === collect([$visible->id, $interested->id])->sort()->values()->all())
        );
});

it('does not exclude another user dismissed events', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $event = Event::factory()->create(['starts_at' => now()->addDays(2)]);
    UserEventReaction::factory()->create([
        'user_id' => $other->id,
        'event_id' => $event->id,
        'reaction' => Reaction::Hidden,
    ]);

    $this->actingAs($user)
        ->get('/events')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('events.data', 1));
});

it('includes the current user reaction on each event for highlight state', function () {
    $user = User::factory()->create();

    $saved = Event::factory()->create(['starts_at' => now()->addDays(2)]);
    $plain = Event::factory()->create(['starts_at' => now()->addDays(2)]);

    UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $saved->id,
        'reaction' => Reaction::Saved,
    ]);

    $this->actingAs($user)
        ->get('/events')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events.data', 2)
            ->where('events.data', fn ($events) => collect($events)->firstWhere('id', $saved->id)['current_reaction'] === 'saved'
                && collect($events)->firstWhere('id', $plain->id)['current_reaction'] === null)
        );
});

it('exposes the current user reaction on the event detail page', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create(['starts_at' => now()->addDays(2)]);

    UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'reaction' => Reaction::Interested,
    ]);

    $this->actingAs($user)
        ->get("/events/{$event->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Events/Show')
            ->where('event.current_reaction', 'interested')
        );
});
