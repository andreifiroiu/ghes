<?php

declare(strict_types=1);

use App\Models\User;

it('gives a new account the covered city when none is supplied', function () {
    $user = User::create([
        'name' => 'Ana',
        'email' => 'ana@example.test',
        'password' => 'password',
    ]);

    expect($user->city)->toBe('Timișoara');
});

it('honours an explicit null city', function () {
    // "No city" is a real state the dashboard and recommendation engine
    // handle on purpose, so saying null must not be silently overruled.
    $user = User::create([
        'name' => 'Bogdan',
        'email' => 'bogdan@example.test',
        'password' => 'password',
        'city' => null,
    ]);

    expect($user->city)->toBeNull();
});

it('gives API signups without a city the covered one', function () {
    $this->postJson('/api/auth/register', [
        'name' => 'Elena',
        'email' => 'elena@example.test',
        'password' => 'password123',
    ])->assertCreated();

    expect(User::where('email', 'elena@example.test')->sole()->city)->toBe('Timișoara');
});

it('never overwrites a city the caller chose', function () {
    $user = User::create([
        'name' => 'Carmen',
        'email' => 'carmen@example.test',
        'password' => 'password',
        'city' => 'Bucharest',
    ]);

    expect($user->city)->toBe('Bucharest');
});

it('gives users who register on the web a city straight away', function () {
    $this->post('/register', [
        'name' => 'Dan',
        'email' => 'dan@example.test',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    expect(User::where('email', 'dan@example.test')->sole()->city)->toBe('Timișoara');
});

it('honours an explicit null city from the API', function () {
    // User::booted() documents that an explicit null means "no city"; the one
    // signup path that accepts the field has to be able to express it.
    $this->postJson('/api/auth/register', [
        'name' => 'Florin',
        'email' => 'florin@example.test',
        'password' => 'password123',
        'city' => null,
    ])->assertCreated();

    expect(User::where('email', 'florin@example.test')->sole()->city)->toBeNull();
});

it('refuses to register with a city no source covers', function () {
    // Registration and PUT /api/profile used to disagree, so a client could
    // create an account holding a city the profile endpoint rejects forever.
    $this->postJson('/api/auth/register', [
        'name' => 'Gabi',
        'email' => 'gabi@example.test',
        'password' => 'password123',
        'city' => 'Cluj-Napoca',
    ])->assertStatus(422)->assertJsonValidationErrors('city');
});

it('accepts a covered city at registration', function () {
    $this->postJson('/api/auth/register', [
        'name' => 'Horia',
        'email' => 'horia@example.test',
        'password' => 'password123',
        'city' => 'Timișoara',
    ])->assertCreated();

    expect(User::where('email', 'horia@example.test')->sole()->city)->toBe('Timișoara');
});
