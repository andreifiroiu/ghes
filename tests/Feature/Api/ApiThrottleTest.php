<?php

declare(strict_types=1);

use App\Models\User;

it('blocks the sixth sign-in attempt for one address from one ip', function () {
    $user = User::factory()->create();
    $attempt = fn (string $email) => $this->postJson('/api/v1/auth/login', [
        'email' => $email,
        'password' => 'wrong',
        'device_name' => 'p',
        'platform' => 'ios',
    ]);

    foreach (range(1, 5) as $i) {
        $attempt($user->email)->assertStatus(422);
    }

    $attempt($user->email)
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited');

    // Keyed by address as well as IP: a different address is a different bucket.
    $attempt('someone-else@example.test')->assertStatus(422);
});

it('bounds sign-in attempts per ip across addresses', function () {
    config(['eventpulse.api.throttle.auth_per_minute_per_ip' => 3]);
    $attempt = fn (int $i) => $this->postJson('/api/v1/auth/login', [
        'email' => "victim{$i}@example.test",
        'password' => 'wrong',
        'device_name' => 'p',
        'platform' => 'ios',
    ]);

    foreach (range(1, 3) as $i) {
        $attempt($i)->assertStatus(422);
    }

    $attempt(4)->assertStatus(429);
});

it('limits registrations per ip per hour', function () {
    config(['eventpulse.api.throttle.register_per_hour' => 2]);
    $register = fn (int $i) => $this->postJson('/api/v1/auth/register', [
        'name' => 'N',
        'email' => "u{$i}@example.test",
        'password' => 'password123',
        'device_name' => 'p',
        'platform' => 'ios',
    ]);

    $register(1)->assertStatus(201);
    $register(2)->assertStatus(201);
    $register(3)->assertStatus(429);
});

it('applies the api limiter to authenticated routes', function () {
    config(['eventpulse.api.throttle.per_minute' => 2]);
    $user = User::factory()->create();
    $token = $user->createToken('access', ['api:access'])->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/profile')->assertOk();
    $this->withToken($token)->getJson('/api/v1/profile')->assertOk();
    $this->withToken($token)->getJson('/api/v1/profile')->assertStatus(429);
});
