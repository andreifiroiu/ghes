<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventBookmark;
use App\Models\Notification;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('requires authentication', function () {
    $this->getJson('/api/v1/profile')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('returns the authenticated user profile', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['*']);

    $this->getJson('/api/v1/profile')
        ->assertStatus(200)
        ->assertJsonPath('data.id', $user->id);
});

it('returns profile stats with reactions and discovery hit-rate', function () {
    $user = User::factory()->create();

    Event::factory()->count(2)->create()->each(
        fn (Event $event) => EventBookmark::factory()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
        ])
    );

    Sanctum::actingAs($user, ['*']);

    $this->getJson('/api/v1/profile/stats')
        ->assertStatus(200)
        ->assertJsonPath('data.reactions.saved', 2)
        ->assertJsonStructure([
            'data' => [
                'reactions' => ['total', 'by_type', 'saved'],
                'discovery' => ['openness', 'surfaced', 'resolved', 'hits', 'hit_rate'],
            ],
        ]);
});

it('lists notification history', function () {
    $user = User::factory()->create();
    Notification::factory()->count(3)->create(['user_id' => $user->id]);

    Sanctum::actingAs($user, ['*']);

    $this->getJson('/api/v1/notifications')
        ->assertStatus(200)
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.total', 3);
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

    Sanctum::actingAs($user, ['*']);

    $this->getJson('/api/v1/recommendations/history')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.events.0.id', $event->id);
});

it('returns chat history for a context', function () {
    $user = User::factory()->create();
    $user->chatMessages()->create(['role' => 'user', 'content' => 'salut', 'context' => 'onboarding']);

    Sanctum::actingAs($user, ['*']);

    $this->getJson('/api/v1/chat/history')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data');
});

it('forbids admin event stats for non-admins', function () {
    Sanctum::actingAs(User::factory()->create(), ['*']);

    $this->getJson('/api/v1/admin/events/stats')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');
});

it('returns admin event stats for admins', function () {
    $user = User::factory()->create();
    config(['eventpulse.admin_emails' => [$user->email]]);

    Sanctum::actingAs($user, ['*']);

    $this->getJson('/api/v1/admin/events/stats')
        ->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                'events' => ['total', 'classified', 'geocoded', 'enriched', 'by_category'],
                'scraper_runs' => ['total', 'failed'],
            ],
        ]);
});
