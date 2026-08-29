<?php

declare(strict_types=1);

use App\Enums\Reaction;
use App\Models\Event;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserEventReaction;
use Laravel\Sanctum\Sanctum;

it('requires authentication', function () {
    $this->getJson('/api/profile')->assertStatus(401);
});

it('returns the authenticated user profile', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/profile')
        ->assertStatus(200)
        ->assertJsonPath('id', $user->id);
});

it('returns profile stats with reactions and discovery hit-rate', function () {
    $user = User::factory()->create();

    Event::factory()->count(2)->create()->each(
        fn (Event $event) => UserEventReaction::factory()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'reaction' => Reaction::Saved,
        ])
    );

    Sanctum::actingAs($user);

    $this->getJson('/api/profile/stats')
        ->assertStatus(200)
        ->assertJsonPath('reactions.saved', 2)
        ->assertJsonStructure([
            'reactions' => ['total', 'by_type', 'saved'],
            'discovery' => ['openness', 'surfaced', 'resolved', 'hits', 'hit_rate'],
        ]);
});

it('lists notification history', function () {
    $user = User::factory()->create();
    Notification::factory()->count(3)->create(['user_id' => $user->id]);

    Sanctum::actingAs($user);

    $this->getJson('/api/notifications')
        ->assertStatus(200)
        ->assertJsonStructure(['data', 'total'])
        ->assertJsonPath('total', 3);
});

it('returns recommendation history from sent notifications', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    Notification::factory()->create([
        'user_id' => $user->id,
        'event_ids' => [$event->id],
        'discovery_event_ids' => [],
        'sent_at' => now(),
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/recommendations/history')
        ->assertStatus(200)
        ->assertJsonCount(1, 'history');
});

it('returns chat history for a context', function () {
    $user = User::factory()->create();
    $user->chatMessages()->create(['role' => 'user', 'content' => 'salut', 'context' => 'onboarding']);

    Sanctum::actingAs($user);

    $this->getJson('/api/chat/history')
        ->assertStatus(200)
        ->assertJsonCount(1);
});

it('forbids admin event stats for non-admins', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/admin/events/stats')->assertStatus(403);
});

it('returns admin event stats for admins', function () {
    $user = User::factory()->create();
    config(['eventpulse.admin_emails' => [$user->email]]);

    Sanctum::actingAs($user);

    $this->getJson('/api/admin/events/stats')
        ->assertStatus(200)
        ->assertJsonStructure([
            'events' => ['total', 'classified', 'geocoded', 'enriched', 'by_category'],
            'scraper_runs' => ['total', 'failed'],
        ]);
});
