<?php

declare(strict_types=1);

use App\Enums\EventCategory;
use App\Models\Event;
use App\Models\User;

beforeEach(function () {
    $this->withoutVite();
});

it('shows recommendations on dashboard for authenticated user', function () {
    $user = User::factory()->create([
        'interest_profile' => ['music' => 0.8],
        'city' => 'Bucharest',
        'onboarding_completed' => true,
    ]);

    Event::factory()->count(5)->create([
        'category' => EventCategory::Music,
        'city' => 'Bucharest',
        'starts_at' => now()->addDays(3),
        'is_classified' => true,
    ]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertStatus(200);
});

it('requires authentication to view dashboard', function () {
    $response = $this->get('/dashboard');

    $response->assertRedirect('/login');
});
