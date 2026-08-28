<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;

function fakeGoogleUser(string $email, ?string $name = 'Google User'): void
{
    $oauthUser = Mockery::mock(SocialiteUser::class);
    $oauthUser->shouldReceive('getEmail')->andReturn($email);
    $oauthUser->shouldReceive('getName')->andReturn($name);
    $oauthUser->shouldReceive('getNickname')->andReturn(null);

    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->andReturn($oauthUser);

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
}

it('creates a new user from the google callback and logs in', function () {
    fakeGoogleUser('new@gmail.com');

    $response = $this->get('/auth/google/callback');

    $response->assertRedirect(route('onboarding'));
    $this->assertDatabaseHas('users', ['email' => 'new@gmail.com', 'onboarding_completed' => false]);
    $this->assertAuthenticated();
});

it('logs in an existing user via google', function () {
    $user = User::factory()->create([
        'email' => 'existing@gmail.com',
        'onboarding_completed' => true,
    ]);

    fakeGoogleUser('existing@gmail.com');

    $response = $this->get('/auth/google/callback');

    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);
});

it('redirects to the provider consent screen', function () {
    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));
    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    $this->get('/auth/google/redirect')->assertRedirect();
});

it('returns 404 for an unsupported provider', function () {
    $this->get('/auth/github/redirect')->assertNotFound();
});
