<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('returns the settings with the accepted values and no vapid key', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['*']);

    $response = $this->getJson('/api/v1/settings/notifications')->assertOk();

    expect($response->json('data.user.id'))->toBe($user->id)
        ->and($response->json('data.channels'))->toBe(['email', 'push', 'both'])
        ->and($response->json('data.frequencies'))->toBe(['daily', 'weekly', 'realtime'])
        ->and($response->json('data'))->not->toHaveKey('vapidPublicKey');
});

it('updates the settings and returns the user', function () {
    $user = User::factory()->create(['discovery_openness' => 0.3]);
    Sanctum::actingAs($user, ['*']);

    $this->putJson('/api/v1/settings/notifications', ['channel' => 'push', 'frequency' => 'weekly'])
        ->assertOk()
        ->assertJsonPath('data.notification_channel', 'push')
        ->assertJsonPath('data.notification_frequency', 'weekly')
        ->assertJsonPath('data.discovery_openness', 0.3);

    $this->putJson('/api/v1/settings/notifications', ['channel' => 'email', 'frequency' => 'daily', 'discovery_openness' => 0.8])
        ->assertOk()
        ->assertJsonPath('data.discovery_openness', 0.8);
});

it('validates against the enum values', function () {
    Sanctum::actingAs(User::factory()->create(), ['*']);

    $this->putJson('/api/v1/settings/notifications', ['channel' => 'pigeon', 'frequency' => 'daily'])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['channel']]]);
});
