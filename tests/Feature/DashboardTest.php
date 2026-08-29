<?php

declare(strict_types=1);

use App\Enums\EventCategory;
use App\Enums\Reaction;
use App\Models\Event;
use App\Models\EventBookmark;
use App\Models\User;
use App\Models\UserEventReaction;
use App\Services\Recommendation\RecommendationEngine;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->withoutVite();
});

it('renders the dashboard with every section populated', function () {
    // Pinned to a Wednesday so "the upcoming weekend" is a known date range
    // rather than whatever day the suite happens to run on — otherwise the
    // weekend assertion below silently tests nothing.
    Carbon::setTestNow(Carbon::parse('2026-09-02 09:00:00', 'Europe/Bucharest'));

    $user = User::factory()->create([
        'city' => 'Timișoara',
        'interest_profile' => ['music' => 0.9, 'arts' => 0.7],
        'discovery_openness' => 0.25,
    ]);

    Event::factory()->count(12)->create([
        'city' => 'Timișoara',
        'starts_at' => now()->addDays(1),
        'is_classified' => true,
    ]);

    // Saturday 2026-09-05, inside the weekend window.
    Event::factory()->count(3)->create([
        'city' => 'Timișoara',
        'starts_at' => Carbon::parse('2026-09-05 19:00:00', 'Europe/Bucharest'),
        'is_classified' => true,
    ]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard/Index')
            // Counts, not key existence: `has('recommendations')` passes on [].
            ->has('recommendations', 6)
            ->has('discoveryEvents', 2)
            ->has('weekendEvents', 3)
            ->where('stats.upcoming', 15)
            ->where('stats.saved', 0)
            ->where('stats.interested', 0)
            ->has('stats.profile_completeness')
            ->where('city', 'Timișoara')
            ->where('hasCity', true)
            ->where('hasEventsInCity', true)
        );

    Carbon::setTestNow();
});

it('keeps the recommended list in the order the engine ranked it', function () {
    $user = User::factory()->create([
        'city' => 'Timișoara',
        'interest_profile' => ['music' => 0.95, 'sports' => 0.01],
        'discovery_openness' => 0.125,
    ]);

    // Staggered start times so the candidate query's `orderBy('starts_at')` has
    // no ties and the batch is fully deterministic.
    collect([EventCategory::Sports, EventCategory::Music])
        ->each(fn (EventCategory $category, int $group) => Event::factory()->count(3)->sequence(
            fn ($sequence) => ['starts_at' => now()->addDays(3 + $group * 3 + $sequence->index)],
        )->create([
            'category' => $category,
            'city' => 'Timișoara',
            'is_classified' => true,
        ]));

    $expectedOrder = app(RecommendationEngine::class)->recommend($user)->recommendedEventIds;

    $response = $this->actingAs($user)->get('/dashboard');

    $servedOrder = array_column(
        $response->viewData('page')['props']['recommendations'],
        'id',
    );

    // whereIn() returns database order, so without inBatchOrder() the served
    // order is the insertion order — sports first — rather than the ranking.
    expect($servedOrder)->toBe($expectedOrder)
        ->and($servedOrder)->not->toBeEmpty();
});

it('excludes a dismissed event from the weekend section', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-02 09:00:00', 'Europe/Bucharest'));

    $user = User::factory()->create(['city' => 'Timișoara']);

    $events = Event::factory()->count(2)->create([
        'city' => 'Timișoara',
        'starts_at' => Carbon::parse('2026-09-05 19:00:00', 'Europe/Bucharest'),
        'is_classified' => true,
    ]);

    UserEventReaction::query()->create([
        'user_id' => $user->id,
        'event_id' => $events->first()->id,
        'reaction' => Reaction::NotInterested,
    ]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('weekendEvents', 1));

    Carbon::setTestNow();
});

it('surfaces events whose city differs from the user city only by diacritics', function () {
    // The dashboard-is-empty bug: users.city comes from the onboarding chat
    // without diacritics, events carry the scraper's spelling with them.
    $user = User::factory()->create([
        'city' => 'Timisoara',
        'interest_profile' => ['music' => 0.9],
        'discovery_openness' => 0.25,
    ]);

    Event::factory()->count(10)->create([
        'category' => EventCategory::Music,
        'city' => 'Timișoara',
        'starts_at' => now()->addDays(4),
        'is_classified' => true,
    ]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('hasEventsInCity', true)
            ->where('stats.upcoming', 10)
        );

    // The regression itself: an exact city comparison returns nothing here.
    expect($response->viewData('page')['props']['recommendations'])->not->toBeEmpty();
});

it('reports no events in the city when they are all elsewhere', function () {
    $user = User::factory()->create(['city' => 'Timișoara']);

    Event::factory()->count(5)->create([
        'city' => 'Cluj-Napoca',
        'starts_at' => now()->addDays(4),
        'is_classified' => true,
    ]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('hasEventsInCity', false)
            ->where('stats.upcoming', 0)
            ->where('recommendations', [])
        );
});

it('flags an unfinished onboarding so the page can prompt for it', function () {
    $user = User::factory()->notOnboarded()->create(['city' => 'Timișoara']);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('onboardingCompleted', false)
            ->where('stats.profile_completeness', 0)
        );
});

it('flags a missing city and falls back to the configured city label', function () {
    $user = User::factory()->create(['city' => null]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('hasCity', false)
            ->where('city', config('eventpulse.cities.'.config('eventpulse.default_city').'.label'))
        );
});

it('counts saved and interested events in the stats', function () {
    $user = User::factory()->create(['city' => 'Timișoara']);

    $events = Event::factory()->count(4)->create([
        'city' => 'Timișoara',
        'starts_at' => now()->addDays(4),
        'is_classified' => true,
    ]);

    EventBookmark::query()->create([
        'user_id' => $user->id,
        'event_id' => $events->first()->id,
    ]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('stats.saved', 1)
            ->where('stats.interested', 0)
        );
});

it('treats a city that cannot be slugged as no city at all', function (string $junkCity) {
    // users.city is free LLM text from the onboarding chat with no validation.
    // A non-empty string that slugs to null makes `when()` skip the filter, so
    // the user would silently be served every city's events under a header
    // naming their junk city — and the "set your city" prompt would never fire.
    $user = User::factory()->create(['city' => $junkCity]);

    Event::factory()->count(4)->create([
        'city' => 'Cluj-Napoca',
        'starts_at' => now()->addDays(4),
        'is_classified' => true,
    ]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('hasCity', false)
            ->where('city', config('eventpulse.cities.'.config('eventpulse.default_city').'.label'))
        );
})->with(['!!!', '   ', '___']);

it('does not skip the city filter for a city named "0"', function () {
    // Laravel's when() treats "0" as falsy, so a bare ->when($citySlug, …)
    // silently drops the filter and serves every city's events.
    $user = User::factory()->create(['city' => '0']);

    Event::factory()->count(4)->create([
        'city' => 'Cluj-Napoca',
        'starts_at' => now()->addDays(4),
        'is_classified' => true,
    ]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('hasCity', true)
            ->where('stats.upcoming', 0)
            ->where('recommendations', [])
        );
});

it('reaches 100 percent completeness without a score for the Other bucket', function () {
    // `Other` is the classifier's fallback and ProfileGenerator never assigns
    // it, so counting it in the denominator would cap every finished profile
    // at 93% and show the "finish your profile" hint forever.
    $profile = collect(EventCategory::cases())
        ->reject(fn (EventCategory $category) => $category === EventCategory::Other)
        ->mapWithKeys(fn (EventCategory $category) => [$category->value => 0.5])
        ->all();

    $user = User::factory()->create(['city' => 'Timișoara', 'interest_profile' => $profile]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('stats.profile_completeness', 100)
        );
});
