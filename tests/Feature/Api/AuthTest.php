<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/** The guard's "recaller" cookie — its name is derived, never hardcoded. */
function rememberCookieName(): string
{
    return Auth::guard('web')->getRecallerName();
}

it('can register a new user', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
    $this->assertAuthenticated();
});

it('cannot register with invalid email', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'not-an-email',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('cannot register with duplicate email', function () {
    User::factory()->create(['email' => 'test@example.com']);

    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertSessionHasErrors('email');
});

it('can login with valid credentials', function () {
    $user = User::factory()->create([
        'password' => bcrypt('password123'),
    ]);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password123',
    ]);

    $response->assertRedirect();
    $this->assertAuthenticatedAs($user);
});

it('cannot login with wrong password', function () {
    $user = User::factory()->create([
        'password' => bcrypt('password123'),
    ]);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrors();
    $this->assertGuest();
});

it('can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $response->assertRedirect();
    $this->assertGuest();
});

it('does not remember the login by default', function () {
    $user = User::factory()->create([
        'password' => bcrypt('password123'),
    ]);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password123',
    ])->assertCookieMissing(rememberCookieName());

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->remember_token)->toBe($user->remember_token);
});

it('issues a remember cookie when remember me is checked', function () {
    $user = User::factory()->create([
        'password' => bcrypt('password123'),
    ]);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password123',
        'remember' => true,
    ])->assertCookie(rememberCookieName());

    $this->assertAuthenticatedAs($user);
});

it('mints a remember token for a user that has none', function () {
    $user = User::factory()->create([
        'password' => bcrypt('password123'),
        'remember_token' => null,
    ]);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password123',
        'remember' => true,
    ])->assertCookie(rememberCookieName());

    expect($user->fresh()->remember_token)->not->toBeEmpty();
});

it('stops honouring the remember cookie after logout', function () {
    $user = User::factory()->create([
        'password' => bcrypt('password123'),
    ]);

    $login = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password123',
        'remember' => true,
    ]);

    $recaller = $login->getCookie(rememberCookieName())?->getValue();
    expect($recaller)->not->toBeNull();

    $this->post('/logout');

    // Replay the recaller on its own against a guarded route. Asserting only
    // that the column rotated would pass with remember-me removed entirely —
    // logout cycles a factory-seeded token regardless of this feature.
    $this->flushSession();
    app('auth')->forgetGuards();

    $this->withCookie(rememberCookieName(), $recaller)
        ->get('/dashboard')
        ->assertRedirect(route('login'));
});

it('reads an HTML checkbox "on" as remember me', function () {
    $user = User::factory()->create([
        'password' => bcrypt('password123'),
    ]);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password123',
        'remember' => 'on',
    ])->assertCookie(rememberCookieName());

    $this->assertAuthenticatedAs($user);
});

it('logs in without remembering when the flag is unrecognised', function () {
    $user = User::factory()->create([
        'password' => bcrypt('password123'),
    ]);

    // A junk value must never cost the user their login — it means "off".
    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password123',
        'remember' => 'nonsense',
    ])->assertCookieMissing(rememberCookieName());

    $this->assertAuthenticatedAs($user);
});

it('logs in without remembering when the flag is null', function () {
    $user = User::factory()->create([
        'password' => bcrypt('password123'),
    ]);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password123',
        'remember' => null,
    ])->assertCookieMissing(rememberCookieName());

    $this->assertAuthenticatedAs($user);
});
