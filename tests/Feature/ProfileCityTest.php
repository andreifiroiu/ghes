<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\City\CityCatalog;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->withoutVite();
});

it('offers the covered cities on the profile page', function () {
    $user = User::factory()->create(['city' => 'Timișoara']);

    $this->actingAs($user)
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard/Profile')
            ->where('cityOptions', CityCatalog::labels())
            ->where('user.city', 'Timișoara')
        );
});

it('still reports a city that is no longer covered', function () {
    // The select has to offer it back, otherwise the page shows a city the
    // account does not actually have.
    $user = User::factory()->create(['city' => 'Bucharest']);

    $this->actingAs($user)
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('user.city', 'Bucharest')
            ->where('cityOptions', CityCatalog::labels())
        );
});

it('saves a covered city from the profile form', function () {
    $user = User::factory()->create(['city' => 'Bucharest']);

    $this->actingAs($user)
        ->put('/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'city' => 'Timișoara',
        ])
        ->assertRedirect(route('profile.show'))
        ->assertSessionHasNoErrors();

    expect($user->refresh()->city)->toBe('Timișoara');
});

it('refuses a city no source covers and leaves the user untouched', function () {
    $user = User::factory()->create(['city' => 'Timișoara']);

    $this->actingAs($user)
        ->put('/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'city' => 'București',
        ])
        ->assertSessionHasErrors('city');

    expect($user->refresh()->city)->toBe('Timișoara');
});

it('lets an account with an uncovered city still edit its name', function () {
    // The form posts every field at once, so rejecting the stored city would
    // silently discard the name change alongside it.
    $user = User::factory()->create(['city' => 'Bucharest', 'name' => 'Nume Vechi']);

    $this->actingAs($user)
        ->put('/profile', [
            'name' => 'Nume Nou',
            'email' => $user->email,
            'city' => 'Bucharest',
        ])
        ->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->name)->toBe('Nume Nou')
        ->and($user->city)->toBe('Bucharest');
});

it('lets an account with no city save without choosing one', function () {
    $user = User::factory()->create(['name' => 'Nume Vechi']);
    $user->forceFill(['city' => null])->saveQuietly();

    $this->actingAs($user)
        ->put('/profile', [
            'name' => 'Nume Nou',
            'email' => $user->email,
            'city' => '',
        ])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->name)->toBe('Nume Nou');
});

it('does not let an account clear a city it already has', function () {
    $user = User::factory()->create(['city' => 'Timișoara']);

    $this->actingAs($user)
        ->put('/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'city' => '',
        ])
        ->assertSessionHasErrors('city');

    expect($user->refresh()->city)->toBe('Timișoara');
});
