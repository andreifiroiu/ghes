<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\InterestProfile\ProfileDecayer;

beforeEach(function () {
    $this->decayer = new ProfileDecayer;
    config()->set('eventpulse.profile.decay_rate', 0.10);
});

it('multiplies every numeric score by the decay rate', function () {
    $user = User::factory()->create([
        'interest_profile' => ['music' => 0.80, 'sports' => 0.50],
    ]);

    expect($this->decayer->decay($user))->toBeTrue();

    $user->refresh();
    expect($user->interest_profile['music'])->toEqualWithDelta(0.72, 0.0001)
        ->and($user->interest_profile['sports'])->toEqualWithDelta(0.45, 0.0001);
});

it('leaves non-numeric profile values untouched', function () {
    $user = User::factory()->create([
        'interest_profile' => ['music' => 0.80, 'city' => 'Timișoara'],
    ]);

    $this->decayer->decay($user);

    $user->refresh();
    expect($user->interest_profile['city'])->toBe('Timișoara');
});

it('reports an empty profile as nothing to decay', function () {
    $user = User::factory()->create(['interest_profile' => []]);

    expect($this->decayer->decay($user))->toBeFalse();
});

/**
 * The empty-profile filter used to run in SQL as `interest_profile != '{}'`,
 * which PostgreSQL rejects outright — the column is `json`, a type with no
 * equality operator — so the command died before decaying anyone.
 */
it('decays every user with a profile without comparing json in the query', function () {
    $withScores = User::factory()->count(3)->create([
        'interest_profile' => ['music' => 0.80],
    ]);
    User::factory()->create(['interest_profile' => []]);

    expect($this->decayer->decayAll())->toBe(3);

    foreach ($withScores as $user) {
        expect($user->fresh()->interest_profile['music'])->toEqualWithDelta(0.72, 0.0001);
    }
});

it('runs the decay command end to end', function () {
    User::factory()->create(['interest_profile' => ['music' => 0.80]]);

    $this->artisan('eventpulse:decay-profiles')
        ->expectsOutputToContain('Decayed profiles for 1 users.')
        ->assertSuccessful();
});
