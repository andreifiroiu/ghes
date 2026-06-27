<?php

declare(strict_types=1);

use App\Enums\EventCategory;
use App\Models\Event;
use App\Models\User;
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
