<?php

declare(strict_types=1);

use App\Enums\Reaction;
use App\Models\Event;
use App\Models\User;
use App\Models\UserEventReaction;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->withoutVite();
    $this->admin = User::factory()->create();
    config(['eventpulse.admin_emails' => [$this->admin->email]]);
});

it('lists users', function () {
    User::factory()->count(3)->create();

    $this->actingAs($this->admin)->get('/admin/users')
        ->assertStatus(200)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Users/Index')
            ->has('users.data'));
});

it('filters users by search', function () {
    User::factory()->create(['email' => 'findme@example.com']);

    $this->actingAs($this->admin)->get('/admin/users?search=findme')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('users.data', 1));
});

it('shows a user with insights', function () {
    $user = User::factory()->create(['interest_profile' => ['music' => 0.7]]);
    $event = Event::factory()->create();
    UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'reaction' => Reaction::Saved,
    ]);

    $this->actingAs($this->admin)->get("/admin/users/{$user->id}")
        ->assertStatus(200)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Users/Show')
            ->where('user.id', $user->id)
            ->has('insights.interest_profile')
            ->has('insights.discovery'));
});

it('updates a user', function () {
    $user = User::factory()->create(['city' => 'Bucharest']);

    $this->actingAs($this->admin)
        ->put("/admin/users/{$user->id}", ['name' => 'Renamed', 'city' => 'Cluj-Napoca'])
        ->assertRedirect(route('admin.users.show', $user));

    expect($user->fresh()->name)->toBe('Renamed')
        ->and($user->fresh()->city)->toBe('Cluj-Napoca');
});

it('deletes a user', function () {
    $user = User::factory()->create();

    $this->actingAs($this->admin)
        ->delete("/admin/users/{$user->id}")
        ->assertRedirect(route('admin.users.index'));

    expect(User::find($user->id))->toBeNull();
});

it('cannot delete itself', function () {
    $this->actingAs($this->admin)
        ->delete("/admin/users/{$this->admin->id}")
        ->assertRedirect();

    expect(User::find($this->admin->id))->not->toBeNull();
});
