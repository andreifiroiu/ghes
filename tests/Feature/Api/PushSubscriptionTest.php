<?php

declare(strict_types=1);

use App\Models\User;

it('stores a push subscription', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/push/subscribe', [
        'endpoint' => 'https://push.example.com/abc',
        'keys' => ['p256dh' => 'public-key', 'auth' => 'auth-token'],
    ])->assertStatus(201);

    $this->assertDatabaseHas('push_subscriptions', [
        'user_id' => $user->id,
        'endpoint' => 'https://push.example.com/abc',
        'public_key' => 'public-key',
    ]);
});

it('updates an existing subscription with the same endpoint', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/push/subscribe', [
        'endpoint' => 'https://push.example.com/abc',
        'keys' => ['p256dh' => 'k1', 'auth' => 'a1'],
    ])->assertStatus(201);

    $this->actingAs($user)->postJson('/push/subscribe', [
        'endpoint' => 'https://push.example.com/abc',
        'keys' => ['p256dh' => 'k2', 'auth' => 'a2'],
    ])->assertStatus(201);

    expect($user->pushSubscriptions()->count())->toBe(1);
    $this->assertDatabaseHas('push_subscriptions', [
        'endpoint' => 'https://push.example.com/abc',
        'public_key' => 'k2',
    ]);
});

it('removes a push subscription', function () {
    $user = User::factory()->create();
    $user->pushSubscriptions()->create([
        'endpoint' => 'https://push.example.com/abc',
        'public_key' => 'k',
        'auth_token' => 'a',
    ]);

    $this->actingAs($user)
        ->deleteJson('/push/subscribe', ['endpoint' => 'https://push.example.com/abc'])
        ->assertStatus(200);

    expect($user->pushSubscriptions()->count())->toBe(0);
});

it('requires authentication to subscribe', function () {
    $this->postJson('/push/subscribe', [
        'endpoint' => 'x',
        'keys' => ['p256dh' => 'k', 'auth' => 'a'],
    ])->assertStatus(401);
});
