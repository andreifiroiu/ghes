<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

function fakeGoogleUser(string $email, ?string $name = 'Google User', bool $verified = true): void
{
    $oauthUser = Mockery::mock(SocialiteUser::class);
    $oauthUser->shouldReceive('getId')->andReturn('google-sub-'.md5($email));
    $oauthUser->shouldReceive('getEmail')->andReturn($email);
    $oauthUser->shouldReceive('getName')->andReturn($name);
    $oauthUser->shouldReceive('getNickname')->andReturn(null);
    $oauthUser->shouldReceive('getRaw')->andReturn(['email_verified' => $verified]);

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

    // Google vouched for the address; the account must say so. This used to
    // be silently dropped because the column is not mass-assignable.
    expect(User::where('email', 'new@gmail.com')->sole()->email_verified_at)->not->toBeNull();
});

it('stores the google identity so a later sign-in links by subject even if the address changed', function () {
    fakeGoogleUser('first@gmail.com');
    $this->get('/auth/google/callback')->assertRedirect(route('onboarding'));
    $user = User::where('email', 'first@gmail.com')->sole();
    expect($user->socialIdentities()->where('provider', 'google')->count())->toBe(1);
    auth()->logout();

    // Same Google account, new address at Google: same Ghes account.
    $oauthUser = Mockery::mock(SocialiteUser::class);
    $oauthUser->shouldReceive('getId')->andReturn('google-sub-'.md5('first@gmail.com'));
    $oauthUser->shouldReceive('getEmail')->andReturn('renamed@gmail.com');
    $oauthUser->shouldReceive('getName')->andReturn('Renamed');
    $oauthUser->shouldReceive('getNickname')->andReturn(null);
    $oauthUser->shouldReceive('getRaw')->andReturn(['email_verified' => true]);
    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->andReturn($oauthUser);
    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    $this->get('/auth/google/callback')->assertRedirect(route('onboarding'));
    $this->assertAuthenticatedAs($user);
    expect(User::count())->toBe(1);
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

it('refuses an unverified google address, so it cannot take over a password account', function () {
    // Accounts link purely by address; the provider vouching for it is what
    // keeps that safe. A deliberate change to the web flow, shared with the
    // native exchange through SocialAccountLinker.
    $victim = User::factory()->create(['email' => 'existing@gmail.com']);

    fakeGoogleUser('existing@gmail.com', verified: false);

    $this->get('/auth/google/callback')
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(User::count())->toBe(1)->and($victim->fresh())->not->toBeNull();
});

it('accepts the older verified_email flag from google', function () {
    $oauthUser = Mockery::mock(SocialiteUser::class);
    $oauthUser->shouldReceive('getId')->andReturn('google-sub-old');
    $oauthUser->shouldReceive('getEmail')->andReturn('old@gmail.com');
    $oauthUser->shouldReceive('getName')->andReturn('Old Flag');
    $oauthUser->shouldReceive('getNickname')->andReturn(null);
    $oauthUser->shouldReceive('getRaw')->andReturn(['verified_email' => true]);
    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->andReturn($oauthUser);
    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    $this->get('/auth/google/callback')->assertRedirect(route('onboarding'));
    $this->assertAuthenticated();
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
