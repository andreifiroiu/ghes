<?php

declare(strict_types=1);

use App\Models\User;

it('registers a new user and returns a token', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Test User',
        'email' => 'new@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'email']]]);

    $this->assertDatabaseHas('users', ['email' => 'new@example.com']);
});

it('logs in with valid credentials and returns a token', function () {
    $user = User::factory()->create();

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertStatus(200)->assertJsonStructure(['data' => ['token']]);
});

it('rejects invalid credentials', function () {
    $user = User::factory()->create();

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
});

it('logs out by revoking the current token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('api')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/logout')
        ->assertStatus(200);

    expect($user->tokens()->count())->toBe(0);
});
