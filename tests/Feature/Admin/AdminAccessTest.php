<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\User;

beforeEach(function () {
    $this->withoutVite();
});

it('forbids non-admins from the admin area', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/admin')->assertStatus(403);
    $this->actingAs($user)->get('/admin/events')->assertStatus(403);
    $this->actingAs($user)->get('/admin/users')->assertStatus(403);
    $this->actingAs($user)->get('/admin/scrapers')->assertStatus(403);
});

it('redirects guests to login', function () {
    $this->get('/admin/events')->assertRedirect('/login');
});

it('allows admins into the admin area', function () {
    $admin = User::factory()->create();
    config(['eventpulse.admin_emails' => [$admin->email]]);

    $this->actingAs($admin)->get('/admin')->assertStatus(200);
    $this->actingAs($admin)->get('/admin/events')->assertStatus(200);
    $this->actingAs($admin)->get('/admin/users')->assertStatus(200);
    $this->actingAs($admin)->get('/admin/scrapers')->assertStatus(200);
});

it('exposes the isAdmin flag to the frontend for admins', function () {
    $admin = User::factory()->create();
    config(['eventpulse.admin_emails' => [$admin->email]]);

    $this->actingAs($admin)->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('auth.isAdmin', true));
});

it('forbids non-admins from admin write endpoints', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user)->put("/admin/events/{$event->id}", ['title' => 'Hacked'])->assertStatus(403);
    $this->actingAs($user)->delete("/admin/events/{$event->id}")->assertStatus(403);
    $this->actingAs($user)->put("/admin/users/{$other->id}", ['name' => 'Hacked'])->assertStatus(403);
    $this->actingAs($user)->post('/admin/scrapers/run', ['city' => 'timisoara'])->assertStatus(403);

    expect($event->fresh()->title)->not->toBe('Hacked')
        ->and($other->fresh()->name)->not->toBe('Hacked');
});

it('shares flash messages with the frontend so admin actions are visible', function () {
    $admin = User::factory()->create();
    config(['eventpulse.admin_emails' => [$admin->email]]);
    $event = Event::factory()->create(['is_hidden' => false]);

    $this->actingAs($admin)
        ->post("/admin/events/{$event->id}/hide")
        ->assertRedirect();

    $this->actingAs($admin)->get('/admin/events')
        ->assertInertia(fn ($page) => $page->where('flash.success', 'Event hidden.'));
});
