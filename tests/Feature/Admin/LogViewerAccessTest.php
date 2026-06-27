<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(function () {
    config(['eventpulse.admin_emails' => ['admin@eventpulse.app']]);
});

it('redirects guests to login', function () {
    $this->get('/log-viewer')->assertRedirect('/login');
});

it('forbids authenticated non-admin users', function () {
    $user = User::factory()->create(['email' => 'someone@example.com']);

    $this->actingAs($user)->get('/log-viewer')->assertForbidden();
});

it('allows users on the admin allow-list', function () {
    $admin = User::factory()->create(['email' => 'admin@eventpulse.app']);

    $this->actingAs($admin)->get('/log-viewer')->assertOk();
});

it('protects the log files API the same way', function () {
    $user = User::factory()->create(['email' => 'someone@example.com']);

    $this->actingAs($user)->getJson('/log-viewer/api/files')->assertForbidden();
});
