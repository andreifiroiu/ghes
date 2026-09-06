<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Auth\GoogleIdTokenVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

const GOOGLE_CLIENT_ID = 'ios-client.apps.googleusercontent.com';

beforeEach(function () {
    config(['services.google.client_ids' => [GOOGLE_CLIENT_ID, 'web-client.apps.googleusercontent.com']]);
});

/**
 * Fake Google's tokeninfo answer. Every claim is a string, as tokeninfo returns them.
 *
 * @param  array<string, string>  $overrides
 */
function fakeTokenInfo(array $overrides = [], int $status = 200): void
{
    Http::fake([
        GoogleIdTokenVerifier::TOKENINFO_URL.'*' => Http::response($status === 200 ? [
            'iss' => 'https://accounts.google.com',
            'aud' => GOOGLE_CLIENT_ID,
            'sub' => '1234567890',
            'email' => 'ana@gmail.com',
            'email_verified' => 'true',
            'name' => 'Ana Pop',
            'exp' => (string) (time() + 3600),
            ...$overrides,
        ] : ['error' => 'invalid_token'], $status),
    ]);
}

/**
 * @return array<string, string>
 */
function googleSignInBody(): array
{
    return ['id_token' => 'a.b.c', 'device_name' => 'Ana\'s iPhone', 'platform' => 'ios'];
}

it('creates the account from a verified google identity and issues a pair', function () {
    fakeTokenInfo();

    $response = $this->postJson('/api/v1/auth/oauth/google', googleSignInBody())
        ->assertOk()
        ->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'device_id', 'user' => ['id', 'email']]])
        ->assertJsonPath('data.user.email', 'ana@gmail.com')
        ->assertJsonPath('data.user.name', 'Ana Pop')
        ->assertJsonPath('data.user.onboarding_completed', false);

    $user = User::where('email', 'ana@gmail.com')->sole();
    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->tokens()->count())->toBe(2);

    Http::assertSent(fn ($request) => str_starts_with($request->url(), GoogleIdTokenVerifier::TOKENINFO_URL)
        && str_contains($request->url(), 'id_token=a.b.c'));
});

it('links an existing account by address and keeps its state', function () {
    $existing = User::factory()->create(['email' => 'ana@gmail.com', 'onboarding_completed' => true, 'name' => 'Ana']);
    fakeTokenInfo();

    $this->postJson('/api/v1/auth/oauth/google', googleSignInBody())
        ->assertOk()
        ->assertJsonPath('data.user.id', $existing->id)
        ->assertJsonPath('data.user.name', 'Ana')
        ->assertJsonPath('data.user.onboarding_completed', true);

    expect(User::count())->toBe(1);
});

it('links a password account whose address differs only in case', function () {
    // Google reports addresses lowercase; nothing lowercases them at
    // registration. Without a case-insensitive match this person would end
    // up with two accounts and a password that opens neither.
    $existing = User::factory()->create(['email' => 'Ana@Gmail.com']);
    fakeTokenInfo(['email' => 'ana@gmail.com']);

    $this->postJson('/api/v1/auth/oauth/google', googleSignInBody())
        ->assertOk()
        ->assertJsonPath('data.user.id', $existing->id);

    expect(User::count())->toBe(1);
});

it('rejects a token minted for another app', function () {
    fakeTokenInfo(['aud' => 'someone-elses-client.apps.googleusercontent.com']);

    $this->postJson('/api/v1/auth/oauth/google', googleSignInBody())
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.details.id_token.0', 'The token was not issued for this app.');

    expect(User::count())->toBe(0);
});

it('rejects an unverified google address, so it cannot take over a password account', function () {
    $victim = User::factory()->create(['email' => 'ana@gmail.com']);
    fakeTokenInfo(['email_verified' => 'false']);

    $this->postJson('/api/v1/auth/oauth/google', googleSignInBody())
        ->assertStatus(422)
        ->assertJsonPath('error.details.id_token.0', 'The Google account email is not verified.');

    expect($victim->tokens()->count())->toBe(0);
});

it('rejects an expired token', function () {
    fakeTokenInfo(['exp' => (string) (time() - 10)]);

    $this->postJson('/api/v1/auth/oauth/google', googleSignInBody())
        ->assertStatus(422)
        ->assertJsonPath('error.details.id_token.0', 'The token has expired.');
});

it('rejects a token from another issuer', function () {
    fakeTokenInfo(['iss' => 'https://evil.example']);

    $this->postJson('/api/v1/auth/oauth/google', googleSignInBody())
        ->assertStatus(422)
        ->assertJsonPath('error.details.id_token.0', 'The token was not issued by Google.');
});

it('rejects a token google itself refuses', function () {
    fakeTokenInfo(status: 400);

    $this->postJson('/api/v1/auth/oauth/google', googleSignInBody())
        ->assertStatus(422)
        ->assertJsonPath('error.details.id_token.0', 'Google did not accept the token.');
});

it('reports google being unreachable as a 503 to retry, not as a bad token', function () {
    Http::fake([
        GoogleIdTokenVerifier::TOKENINFO_URL.'*' => fn () => throw new ConnectionException('timed out'),
    ]);

    $this->postJson('/api/v1/auth/oauth/google', googleSignInBody())
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'service_unavailable')
        ->assertJsonPath('error.retry_after', 30);

    expect(User::count())->toBe(0);
});

it('requires the device fields like every other sign-in', function () {
    fakeTokenInfo();

    $this->postJson('/api/v1/auth/oauth/google', ['id_token' => 'a.b.c'])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['device_name', 'platform']]]);

    Http::assertNothingSent();
});

it('accepts the web client id too, since it is always configured', function () {
    config(['services.google.client_ids' => ['web-client.apps.googleusercontent.com']]);
    fakeTokenInfo(['aud' => 'web-client.apps.googleusercontent.com']);

    $this->postJson('/api/v1/auth/oauth/google', googleSignInBody())->assertOk();
});
