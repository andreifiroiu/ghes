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

it('shows the interests an onboarded account actually has', function () {
    // The reported bug: this page read `interest_profile.categories`, which
    // nothing writes, so every onboarded user saw the "no interests yet" copy.
    $user = User::factory()->create([
        'interest_profile' => [
            'arts' => 0.4,
            'music' => 0.9,
            'tag:jazz' => 0.8,
            'tag:faint' => 0.1,
            'source:iabilet' => 0.7,
        ],
    ]);

    $this->actingAs($user)
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard/Profile')
            ->where('interests.categories', [
                ['key' => 'music', 'score' => 0.9],
                ['key' => 'arts', 'score' => 0.4],
            ])
            ->where('interests.tags', [['key' => 'jazz', 'score' => 0.8]])
            ->where('interests.sources', [['key' => 'iabilet', 'score' => 0.7]])
        );
});

it('passes the chat summary to the page', function () {
    $user = User::factory()->create([
        'profile_summary' => 'Îți plac concertele de jazz.',
        'profile_summary_updated_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('user.profile_summary', 'Îți plac concertele de jazz.')
            ->whereNot('user.profile_summary_updated_at', null)
        );
});

it('summarises the account activity on the page', function () {
    $user = User::factory()->create();
    $event = Event::withoutSyncingToSearch(
        fn () => Event::factory()->create(['category' => EventCategory::Music])
    );

    UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'reaction' => Reaction::Interested,
    ]);

    $this->actingAs($user)
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('activity.reactions.interested', 1)
            ->where('activity.has_activity', true)
            ->where('activity.top_categories', [['category' => 'music', 'count' => 1]])
            ->where('activity.recent.0.event_title', $event->title)
        );
});

it('keeps the empty state for an account with nothing yet', function () {
    $user = User::factory()->create(['interest_profile' => []]);

    $this->actingAs($user)
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('interests.categories', [])
            ->where('user.profile_summary', null)
            ->where('activity.has_activity', false)
        );
});
