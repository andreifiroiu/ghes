<?php

declare(strict_types=1);

use App\Enums\NotificationChannel;
use App\Enums\NotificationFrequency;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();
});

/**
 * The page seeds its form from the props, so the name and shape of the prop
 * carrying the stored preferences is load-bearing: the page read `settings`
 * while the controller sent `user`, which silently reset every visitor's form
 * to email/daily and overwrote their real preference on the next save.
 */
it('renders the stored preferences under the prop the page reads', function () {
    $user = User::factory()->create([
        'notification_channel' => NotificationChannel::Both,
        'notification_frequency' => NotificationFrequency::Weekly,
    ]);

    $this->actingAs($user)
        ->get(route('settings.notifications'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/Notifications')
            ->where('user.notification_channel', 'both')
            ->where('user.notification_frequency', 'weekly')
        );
});

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

it('renders the reminder settings and the lead times on offer', function () {
    config(['eventpulse.reminders.lead_options' => [1440, 180]]);

    $user = User::factory()->create([
        'event_reminders_enabled' => true,
        'reminder_lead_minutes' => [180],
    ]);

    $this->actingAs($user)
        ->get(route('settings.notifications'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('user.event_reminders_enabled', true)
            ->where('user.reminder_lead_minutes', [180])
            ->where('reminderLeadOptions', [1440, 180])
            ->etc()
        );
});

it('round-trips the reminder toggle and lead times through the web form', function () {
    config(['eventpulse.reminders.lead_options' => [1440, 360, 180, 60]]);

    $user = User::factory()->create(['event_reminders_enabled' => true]);

    $this->actingAs($user)
        ->from(route('settings.notifications'))
        ->put('/settings/notifications', [
            'channel' => 'email',
            'frequency' => 'daily',
            'event_reminders' => false,
            'reminder_lead_minutes' => [360],
        ])
        ->assertRedirect(route('settings.notifications'));

    $user->refresh();
    expect($user->event_reminders_enabled)->toBeFalse()
        ->and($user->reminder_lead_minutes)->toBe([360]);
});

/**
 * The mirror of the bug this page already had: a form that does not know about
 * a setting must leave it alone, not reset it.
 */
it('preserves the reminder settings when the form omits them', function () {
    $user = User::factory()->create([
        'event_reminders_enabled' => false,
        'reminder_lead_minutes' => [1440],
    ]);

    $this->actingAs($user)
        ->from(route('settings.notifications'))
        ->put('/settings/notifications', ['channel' => 'push', 'frequency' => 'weekly']);

    $user->refresh();
    expect($user->event_reminders_enabled)->toBeFalse()
        ->and($user->reminder_lead_minutes)->toBe([1440]);
});
