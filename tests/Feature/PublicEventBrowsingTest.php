<?php

declare(strict_types=1);

use App\Enums\Reaction;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

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

it('paginates the events list at the configured page size', function () {
    config(['eventpulse.pagination.events' => 2]);
    Event::factory()->count(3)->create(['starts_at' => now()->addDay()]);

    $this->get('/events')
        ->assertStatus(200)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events.data', 2)
            ->where('events.meta.per_page', 2));
});

/**
 * Load the events list the way the "load more" button does: follow the scroll
 * prop's next cursor until it runs out.
 *
 * @return array<int, array<int, array<string, mixed>>> each batch's events
 */
function loadAllBatches(TestCase $test, ?Closure $betweenBatches = null): array
{
    $batches = [];
    $page = $test->get('/events')->assertOk()->viewData('page');

    while (true) {
        $batches[] = $page['props']['events']['data'];
        $cursor = $page['scrollProps']['events']['nextPage'];

        if ($cursor === null || count($batches) > 10) {
            return $batches;
        }

        if ($betweenBatches !== null) {
            $betweenBatches($batches);
        }

        $pageName = $page['scrollProps']['events']['pageName'];
        $page = $test->get("/events?{$pageName}={$cursor}")->assertOk()->viewData('page');
    }
}

it('exposes the events list as an infinite-scroll prop with a running total', function () {
    config(['eventpulse.pagination.events' => 2]);
    Event::factory()->count(3)->create(['starts_at' => now()->addDay()]);

    $page = $this->get('/events')->assertOk()->viewData('page');

    expect($page['scrollProps']['events'])->toMatchArray([
        'pageName' => 'cursor',
        'previousPage' => null,
        'currentPage' => 1,
        'reset' => false,
    ])->and($page['scrollProps']['events']['nextPage'])->toBeString()
        ->and($page['mergeProps'])->toContain('events.data')
        ->and($page['props']['events']['meta']['total'])->toBe(3);
});

it('reports no next cursor on the last batch', function () {
    config(['eventpulse.pagination.events' => 2]);
    Event::factory()->count(3)->create(['starts_at' => now()->addDay()]);

    $batches = loadAllBatches($this);

    expect($batches)->toHaveCount(2)
        ->and($batches[1])->toHaveCount(1);
});

it('skips no event when one is dismissed between batches', function () {
    config(['eventpulse.pagination.events' => 2]);
    $user = User::factory()->create();
    $events = collect(range(1, 5))->map(
        fn (int $day) => Event::factory()->create(['starts_at' => now()->addDays($day)]),
    );

    $this->actingAs($user);

    // Marking a card not-interested does not reload the list, so the reader
    // taps "load more" with the dismissed card still on screen. Under offset
    // pagination that shifted every later event up one slot and the next
    // batch silently started one event too late.
    $dismissed = [];
    $batches = loadAllBatches($this, function (array $batches) use ($user, &$dismissed) {
        if (count($batches) === 1) {
            $dismissed[] = $batches[0][0]['id'];
            $user->reactions()->create(['event_id' => $batches[0][0]['id'], 'reaction' => Reaction::NotInterested]);
        }
    });

    expect(collect($batches)->flatten(1)->pluck('id')->all())
        ->toBe($events->pluck('id')->all())
        ->and($dismissed)->toHaveCount(1);
});

it('replaces rather than appends the events list when a filter reload asks for a reset', function () {
    Event::factory()->create(['starts_at' => now()->addDay()]);

    $version = app(HandleInertiaRequests::class)->version(request());

    $page = $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $version,
        'X-Inertia-Partial-Component' => 'Events/Index',
        'X-Inertia-Partial-Data' => 'events,filters',
        'X-Inertia-Reset' => 'events',
    ])->get('/events?category=music')->assertOk()->json();

    expect($page['scrollProps']['events']['reset'])->toBeTrue()
        ->and($page['mergeProps'] ?? [])->not->toContain('events.data');
});

it('orders events sharing a start time by id so batches never overlap', function () {
    config(['eventpulse.pagination.events' => 2]);
    $startsAt = now()->addDay()->setTime(20, 0);
    // Inserted in descending id order, so scan order and id order disagree and
    // only an explicit tie-breaker yields the ascending ids asserted below.
    $ids = collect(range(1, 5))->map(fn () => (string) Str::uuid())->sort()->values();
    $events = $ids->reverse()->map(
        fn (string $id) => Event::factory()->create(['id' => $id, 'starts_at' => $startsAt]),
    );

    $loaded = collect(loadAllBatches($this))->flatten(1)->pluck('id');

    expect($loaded->all())->toBe($events->pluck('id')->sort()->values()->all());
});

it('paginates the events api at the configured page size', function () {
    config(['eventpulse.pagination.events' => 2]);
    Event::factory()->count(3)->create(['starts_at' => now()->addDay()]);

    Sanctum::actingAs(User::factory()->create(), ['*']);

    $this->getJson('/api/v1/events')
        ->assertStatus(200)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.per_page', 2);
});
