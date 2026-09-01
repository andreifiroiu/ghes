<?php

declare(strict_types=1);

use App\Enums\EventCategory;
use App\Enums\Reaction;
use App\Models\Event;
use App\Models\EventBookmark;
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
        EventBookmark::factory()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
        ]);
    }

    $this->actingAs($user)
        ->get('/events/saved')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events', 1)
            ->where('events.0.id', $saved->id)
        );
});

it('excludes events the user marked not-interested from the browse list', function () {
    $user = User::factory()->create();

    $visible = Event::factory()->create(['starts_at' => now()->addDays(2)]);
    $notInterested = Event::factory()->create(['starts_at' => now()->addDays(2)]);
    $interested = Event::factory()->create(['starts_at' => now()->addDays(2)]);

    UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $notInterested->id,
        'reaction' => Reaction::NotInterested,
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

it('keeps a bookmarked event in the browse list', function () {
    $user = User::factory()->create();

    $event = Event::factory()->create(['starts_at' => now()->addDays(2)]);
    EventBookmark::factory()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
    ]);

    $this->actingAs($user)
        ->get('/events')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events.data', 1)
            ->where('events.data', fn ($events) => collect($events)->first()['is_saved'] === true)
        );
});

it('does not exclude another user dismissed events', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $event = Event::factory()->create(['starts_at' => now()->addDays(2)]);
    UserEventReaction::factory()->create([
        'user_id' => $other->id,
        'event_id' => $event->id,
        'reaction' => Reaction::NotInterested,
    ]);

    $this->actingAs($user)
        ->get('/events')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('events.data', 1));
});

it('includes the current user reaction on each event for highlight state', function () {
    $user = User::factory()->create();

    $reacted = Event::factory()->create(['starts_at' => now()->addDays(2)]);
    $plain = Event::factory()->create(['starts_at' => now()->addDays(2)]);

    UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $reacted->id,
        'reaction' => Reaction::Interested,
    ]);

    $this->actingAs($user)
        ->get('/events')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events.data', 2)
            ->where('events.data', fn ($events) => collect($events)->firstWhere('id', $reacted->id)['current_reaction'] === 'interested'
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

it('filters events by a search term', function () {
    // The first assertion in the suite to exercise `?search=` with results.
    // It was previously untestable: SCOUT_DRIVER=null made Event::search()
    // return nothing, so `whereIn('id', [])` matched no rows and any such test
    // would have been green against an empty page. EventSearcher's database
    // fallback is what gives this something real to assert on.
    $match = Event::factory()->create([
        'title' => 'Concert de jazz la Capitol',
        'starts_at' => now()->addDays(2),
    ]);
    Event::factory()->create([
        'title' => 'Meci de fotbal',
        'starts_at' => now()->addDays(2),
    ]);

    $this->get('/events?search=jazz')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Events/Index')
            ->has('events.data', 1)
            ->where('events.data.0.id', $match->id)
            ->where('filters.search', 'jazz')
        );
});

it('returns more than a single page of search results', function () {
    // Guards the `take()` fix in EventSearcher. Scout's Meilisearch engine
    // drops a null limit through array_filter() and the engine then applies its
    // own 20-hit default, so a search could never fill a second page.
    Event::factory()->count(25)->create([
        'title' => 'Concert de jazz',
        'starts_at' => now()->addDays(2),
    ]);

    $this->get('/events?search=jazz')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('events.meta.total', 25)
        );
});

it('filters events by an exact tag', function () {
    // The autocomplete offers tags, so picking one has to narrow the list. It
    // cannot go through `search`: the database fallback matches title, venue
    // and description only, so a tag that appears in none of them would have
    // suggested itself and then returned nothing.
    $match = Event::factory()->create([
        'title' => 'Seară deschisă',
        'tags' => ['live-music', 'jazz'],
        'starts_at' => now()->addDays(2),
    ]);
    Event::factory()->create([
        'title' => 'Altceva',
        'tags' => ['tech'],
        'starts_at' => now()->addDays(2),
    ]);

    $this->get('/events?tag=live-music')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events.data', 1)
            ->where('events.data.0.id', $match->id)
            ->where('filters.tag', 'live-music')
        );
});

it('filters events by an exact venue', function () {
    $match = Event::factory()->create([
        'venue' => 'Teatrul Merlin',
        'starts_at' => now()->addDays(2),
    ]);
    Event::factory()->create([
        'venue' => 'Sala Capitol',
        'starts_at' => now()->addDays(2),
    ]);

    $this->get('/events?venue='.urlencode('Teatrul Merlin'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events.data', 1)
            ->where('events.data.0.id', $match->id)
        );
});
