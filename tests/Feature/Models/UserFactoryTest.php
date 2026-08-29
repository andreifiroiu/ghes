<?php

declare(strict_types=1);

use App\Models\User;

it('creates an onboarded user with a scored profile', function () {
    $user = User::factory()->create();

    expect($user->onboarding_completed)->toBeTrue()
        ->and($user->interest_profile)->not->toBeEmpty();
});

/**
 * The state used to set interest_profile to null, which the NOT NULL column
 * rejects — the state could never actually be created.
 */
it('creates a not-onboarded user with an empty profile', function () {
    $user = User::factory()->notOnboarded()->create();

    expect($user->onboarding_completed)->toBeFalse()
        ->and($user->fresh()->interest_profile)->toBe([]);
});

it('creates an unverified user', function () {
    expect(User::factory()->unverified()->create()->email_verified_at)->toBeNull();
});
