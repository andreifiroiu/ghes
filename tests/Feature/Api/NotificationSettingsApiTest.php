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

it('exposes the reminder settings and the lead times on offer', function () {
    config(['eventpulse.reminders.lead_options' => [1440, 180]]);

    $user = User::factory()->create([
        'event_reminders_enabled' => true,
        'reminder_lead_minutes' => null,
    ]);
    Sanctum::actingAs($user, ['*']);

    $response = $this->getJson('/api/v1/settings/notifications')->assertOk();

    expect($response->json('data.reminder_lead_options'))->toBe([1440, 180])
        ->and($response->json('data.user.event_reminders_enabled'))->toBeTrue()
        // Resolved, not raw: a null column means the product default, and a
        // client showing "none selected" would say the opposite of the truth.
        ->and($response->json('data.user.reminder_lead_minutes'))
        ->toBe(array_values((array) config('eventpulse.reminders.default_lead_minutes')));
});

it('round-trips the reminder toggle and the chosen lead times', function () {
    config(['eventpulse.reminders.lead_options' => [1440, 360, 180, 60]]);

    $user = User::factory()->create(['event_reminders_enabled' => true]);
    Sanctum::actingAs($user, ['*']);

    $this->putJson('/api/v1/settings/notifications', [
        'channel' => 'email',
        'frequency' => 'daily',
        'event_reminders' => false,
        'reminder_lead_minutes' => [1440, 60],
    ])
        ->assertOk()
        ->assertJsonPath('data.event_reminders_enabled', false)
        ->assertJsonPath('data.reminder_lead_minutes', [1440, 60]);
});

/**
 * The profile page posts channel and frequency without knowing reminders exist.
 * A page that omits a key must never be read as switching it off.
 */
it('leaves the reminder settings alone when the request omits them', function () {
    config(['eventpulse.reminders.lead_options' => [1440, 180]]);

    $user = User::factory()->create([
        'event_reminders_enabled' => false,
        'reminder_lead_minutes' => [1440],
    ]);
    Sanctum::actingAs($user, ['*']);

    $this->putJson('/api/v1/settings/notifications', ['channel' => 'push', 'frequency' => 'weekly'])
        ->assertOk()
        ->assertJsonPath('data.event_reminders_enabled', false)
        ->assertJsonPath('data.reminder_lead_minutes', [1440]);
});

it('rejects a lead time that is not on offer', function () {
    config(['eventpulse.reminders.lead_options' => [1440, 180]]);

    Sanctum::actingAs(User::factory()->create(), ['*']);

    $this->putJson('/api/v1/settings/notifications', [
        'channel' => 'email',
        'frequency' => 'daily',
        'reminder_lead_minutes' => [7],
    ])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['reminder_lead_minutes.0']]]);
});
