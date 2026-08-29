<?php

declare(strict_types=1);

use App\Enums\Reaction;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->withoutVite();
});

/**
 * The timezone the weekend filter is resolved in.
 */
function cityTimezone(): string
{
    return (string) config(
        'eventpulse.cities.'.config('eventpulse.default_city').'.timezone',
        (string) config('app.timezone'),
    );
}

it('lets a guest browse the events list', function () {
    Event::factory()->count(3)->create(['starts_at' => now()->addDay()]);

    $this->get('/events')
        ->assertStatus(200)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Events/Index')
            ->has('events.data', 3));
});

it('lets a guest open an event page', function () {
    $event = Event::factory()->create(['starts_at' => now()->addDay()]);

    $this->get("/events/{$event->id}")
        ->assertStatus(200)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Events/Show')
            ->where('event.id', $event->id));
});

it('does not expose reaction state to a guest', function () {
    $event = Event::factory()->create(['starts_at' => now()->addDay()]);

    User::factory()->create()->reactions()->create([
        'event_id' => $event->id,
        'reaction' => Reaction::Interested,
    ]);

    $this->get("/events/{$event->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->missing('event.current_reaction'));
});

it('still requires authentication to react', function () {
    $event = Event::factory()->create(['starts_at' => now()->addDay()]);

    $this->postJson('/feedback', [
        'event_id' => $event->id,
        'reaction' => 'interested',
    ])->assertUnauthorized();
});

it('still requires authentication for saved events', function () {
    $this->get('/events/saved')->assertRedirect(route('login'));
});

it('keeps the saved-events route reachable for an authenticated user', function () {
    $this->actingAs(User::factory()->create())
        ->get('/events/saved')
        ->assertStatus(200)
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Dashboard/SavedEvents'));
});

it('hides an event a signed-in user dismissed, but not from guests', function () {
    $event = Event::factory()->create(['starts_at' => now()->addDay()]);
    $user = User::factory()->create();

    $user->reactions()->create([
        'event_id' => $event->id,
        'reaction' => Reaction::NotInterested,
    ]);

    $this->actingAs($user)->get('/events')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('events.data', 0));

    auth()->logout();

    $this->get('/events')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('events.data', 1));
});

it('filters the list to the coming weekend when asked mid-week', function () {
    $timezone = cityTimezone();

    // A Wednesday, so the weekend being asked for is Sat 5 – Sun 6 September.
    Carbon::setTestNow(Carbon::parse('2026-09-02 12:00', $timezone));

    $saturday = Event::factory()->create([
        'starts_at' => Carbon::parse('2026-09-05 20:00', $timezone)->utc(),
    ]);
    $sunday = Event::factory()->create([
        'starts_at' => Carbon::parse('2026-09-06 11:00', $timezone)->utc(),
    ]);
    Event::factory()->create([
        'starts_at' => Carbon::parse('2026-09-07 19:00', $timezone)->utc(),
    ]);

    $this->get('/events?range=weekend')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events.data', 2)
            ->where('events.data.0.id', $saturday->id)
            ->where('events.data.1.id', $sunday->id));
});

it('keeps the weekend in progress rather than skipping to the next one', function () {
    $timezone = cityTimezone();

    // Already Sunday: "weekend" must mean today, not six days away.
    Carbon::setTestNow(Carbon::parse('2026-09-06 10:00', $timezone));

    $tonight = Event::factory()->create([
        'starts_at' => Carbon::parse('2026-09-06 20:00', $timezone)->utc(),
    ]);
    Event::factory()->create([
        'starts_at' => Carbon::parse('2026-09-12 20:00', $timezone)->utc(),
    ]);

    $this->get('/events?range=weekend')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events.data', 1)
            ->where('events.data.0.id', $tonight->id));
});

it('does not let an event id shadow the saved-events route', function () {
    $this->get('/events/saved')->assertRedirect(route('login'));
});
