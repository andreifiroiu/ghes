<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Recommendation\ExperimentAssigner;

beforeEach(function () {
    $this->assigner = new ExperimentAssigner;
});

it('assigns a configured variant and persists it', function () {
    $user = User::factory()->create(['experiment_variant' => null]);

    $variant = $this->assigner->variantFor($user);

    expect($variant)->toBeIn(array_keys(config('eventpulse.experiments.recommendation_weights')));

    $user->refresh();
    expect($user->experiment_variant)->toBe($variant);
});

it('returns a stable variant on repeated calls', function () {
    $user = User::factory()->create(['experiment_variant' => null]);

    $first = $this->assigner->variantFor($user);
    $second = $this->assigner->variantFor($user);

    expect($second)->toBe($first);
});

it('respects an already-assigned variant', function () {
    $user = User::factory()->create(['experiment_variant' => 'freshness_boost']);

    expect($this->assigner->variantFor($user))->toBe('freshness_boost');
});

it('returns the variant weights for the user', function () {
    $user = User::factory()->create(['experiment_variant' => 'freshness_boost']);

    expect($this->assigner->weightsFor($user))
        ->toBe(config('eventpulse.experiments.recommendation_weights.freshness_boost'));
});

it('falls back to default weights when no experiments are configured', function () {
    config(['eventpulse.experiments.recommendation_weights' => []]);

    $user = User::factory()->create(['experiment_variant' => null]);

    expect($this->assigner->variantFor($user))->toBe('control')
        ->and($this->assigner->weightsFor($user))
        ->toBe(config('eventpulse.recommendation.weights'));
});
