<?php

declare(strict_types=1);

use App\Enums\NotificationChannel;
use App\Enums\NotificationFrequency;
use App\Models\User;

it('updates channel, frequency and discovery openness and redirects back', function () {
    $user = User::factory()->create([
        'notification_channel' => NotificationChannel::Email,
        'notification_frequency' => NotificationFrequency::Daily,
        'discovery_openness' => 0.15,
    ]);

    $response = $this->actingAs($user)
        ->from(route('settings.notifications'))
        ->put('/settings/notifications', [
            'channel' => 'both',
            'frequency' => 'weekly',
            'discovery_openness' => 0.6,
        ]);

    $response->assertRedirect(route('settings.notifications'));

    $user->refresh();
    expect($user->notification_channel)->toBe(NotificationChannel::Both)
        ->and($user->notification_frequency)->toBe(NotificationFrequency::Weekly)
        ->and((float) $user->discovery_openness)->toBe(0.6);
});

it('updates channel and frequency without discovery openness, preserving it', function () {
    $user = User::factory()->create([
        'notification_channel' => NotificationChannel::Email,
        'notification_frequency' => NotificationFrequency::Daily,
        'discovery_openness' => 0.42,
    ]);

    $response = $this->actingAs($user)
        ->from(route('settings.notifications'))
        ->put('/settings/notifications', [
            'channel' => 'push',
            'frequency' => 'realtime',
        ]);

    $response->assertRedirect(route('settings.notifications'));

    $user->refresh();
    expect($user->notification_channel)->toBe(NotificationChannel::Push)
        ->and($user->notification_frequency)->toBe(NotificationFrequency::Realtime)
        ->and((float) $user->discovery_openness)->toBe(0.42);
});

it('rejects an invalid channel', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->from(route('settings.notifications'))
        ->put('/settings/notifications', [
            'channel' => 'carrier-pigeon',
            'frequency' => 'daily',
        ]);

    $response->assertSessionHasErrors('channel');
});

it('requires authentication', function () {
    $response = $this->put('/settings/notifications', [
        'channel' => 'email',
        'frequency' => 'daily',
    ]);

    $response->assertRedirect('/login');
});
