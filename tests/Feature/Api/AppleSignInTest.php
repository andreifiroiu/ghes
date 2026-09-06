<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Auth\AppleIdTokenVerifier;
use App\Services\Auth\RsaPublicKey;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const APPLE_CLIENT_ID = 'ro.ghes.app';

/**
 * A throwaway RSA key pair, generated once per process: signing tokens in
 * tests the way Apple does, so the verifier's signature check is real.
 *
 * @return array{private: OpenSSLAsymmetricKey, jwk: array<string, string>}
 */
function appleKeyPair(string $kid = 'kid-1'): array
{
    static $pairs = [];

    if (! isset($pairs[$kid])) {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $details = openssl_pkey_get_details($key);
        $b64 = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        $pairs[$kid] = [
            'private' => $key,
            'jwk' => ['kty' => 'RSA', 'kid' => $kid, 'use' => 'sig', 'alg' => 'RS256', 'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e'])],
        ];
    }

    return $pairs[$kid];
}

/**
 * @param  array<string, mixed>  $claims
 */
function appleToken(array $claims = [], string $kid = 'kid-1', ?OpenSSLAsymmetricKey $signWith = null): string
{
    $b64 = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

    $header = $b64((string) json_encode(['alg' => 'RS256', 'kid' => $kid]));
    $payload = $b64((string) json_encode([
        'iss' => AppleIdTokenVerifier::ISSUER,
        'aud' => APPLE_CLIENT_ID,
        'sub' => '001234.abcdef.5678',
        'exp' => time() + 600,
        'iat' => time(),
        ...$claims,
    ]));

    openssl_sign($header.'.'.$payload, $signature, $signWith ?? appleKeyPair($kid)['private'], OPENSSL_ALGO_SHA256);

    return $header.'.'.$payload.'.'.$b64($signature);
}

/**
 * @param  list<string>  $kids
 */
function fakeAppleJwks(array $kids = ['kid-1']): void
{
    Http::fake([
        AppleIdTokenVerifier::JWKS_URL => Http::response(['keys' => array_map(fn (string $kid) => appleKeyPair($kid)['jwk'], $kids)]),
    ]);
}

/**
 * @return array<string, string>
 */
function appleBody(string $token, ?string $name = null): array
{
    return array_filter([
        'identity_token' => $token,
        'name' => $name,
        'device_name' => 'Ana\'s iPhone',
        'platform' => 'ios',
    ], fn ($v) => $v !== null);
}

beforeEach(function () {
    Cache::flush();
    config(['services.apple.client_ids' => [APPLE_CLIENT_ID]]);
});

it('builds a PEM from a JWK that OpenSSL accepts', function () {
    $pem = RsaPublicKey::pemFromJwk(appleKeyPair()['jwk']);
    $details = openssl_pkey_get_details(openssl_pkey_get_public($pem));

    expect($details['bits'])->toBe(2048)
        ->and($details['rsa']['n'])->toBe(openssl_pkey_get_details(appleKeyPair()['private'])['rsa']['n']);
});

it('creates the account on the first sign-in, with the forwarded name and a stored identity', function () {
    fakeAppleJwks();

    $this->postJson('/api/v1/auth/oauth/apple', appleBody(appleToken(['email' => 'ana@icloud.com', 'email_verified' => 'true']), 'Ana Pop'))
        ->assertOk()
        ->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'user' => ['id']]])
        ->assertJsonPath('data.user.email', 'ana@icloud.com')
        ->assertJsonPath('data.user.name', 'Ana Pop')
        ->assertJsonPath('data.user.onboarding_completed', false);

    $user = User::where('email', 'ana@icloud.com')->sole();
    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->socialIdentities()->where('provider', 'apple')->where('subject', '001234.abcdef.5678')->exists())->toBeTrue();
});

it('links a later sign-in that carries no email by subject', function () {
    fakeAppleJwks();
    $first = $this->postJson('/api/v1/auth/oauth/apple', appleBody(appleToken(['email' => 'ana@icloud.com', 'email_verified' => true])))
        ->assertOk()->json('data.user.id');

    // Apple's second and later tokens omit the email entirely.
    $this->postJson('/api/v1/auth/oauth/apple', appleBody(appleToken()))
        ->assertOk()
        ->assertJsonPath('data.user.id', $first);

    expect(User::count())->toBe(1);
});

it('keeps a relay-address account when the real address later changes', function () {
    fakeAppleJwks();
    $first = $this->postJson('/api/v1/auth/oauth/apple', appleBody(appleToken([
        'email' => 'abc123@privaterelay.appleid.com', 'email_verified' => 'true', 'is_private_email' => 'true',
    ])))->assertOk()->json('data.user.id');

    User::find($first)->update(['email' => 'ana@example.test']);

    $this->postJson('/api/v1/auth/oauth/apple', appleBody(appleToken()))
        ->assertOk()
        ->assertJsonPath('data.user.id', $first);
});

it('links to an existing password account by verified address on first sign-in', function () {
    fakeAppleJwks();
    $existing = User::factory()->create(['email' => 'Ana@Example.test']);

    $this->postJson('/api/v1/auth/oauth/apple', appleBody(appleToken(['email' => 'ana@example.test', 'email_verified' => 'true'])))
        ->assertOk()
        ->assertJsonPath('data.user.id', $existing->id);

    expect(User::count())->toBe(1);
});

it('refuses an unknown subject that brings no email', function () {
    fakeAppleJwks();

    $this->postJson('/api/v1/auth/oauth/apple', appleBody(appleToken()))
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['identity_token']]]);

    expect(User::count())->toBe(0);
});

it('rejects a token for another app, an expired one, and one from another issuer', function () {
    fakeAppleJwks();

    $this->postJson('/api/v1/auth/oauth/apple', appleBody(appleToken(['aud' => 'com.other.app', 'email' => 'x@icloud.com', 'email_verified' => 'true'])))
        ->assertStatus(422)->assertJsonPath('error.details.identity_token.0', 'The token was not issued for this app.');
    $this->postJson('/api/v1/auth/oauth/apple', appleBody(appleToken(['exp' => time() - 5, 'email' => 'x@icloud.com', 'email_verified' => 'true'])))
        ->assertStatus(422)->assertJsonPath('error.details.identity_token.0', 'The token has expired.');
    $this->postJson('/api/v1/auth/oauth/apple', appleBody(appleToken(['iss' => 'https://evil.example', 'email' => 'x@icloud.com', 'email_verified' => 'true'])))
        ->assertStatus(422)->assertJsonPath('error.details.identity_token.0', 'The token was not issued by Apple.');

    expect(User::count())->toBe(0);
});

it('rejects a token signed with a key apple did not publish', function () {
    fakeAppleJwks(['kid-1']);
    $rogue = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

    // Right kid, wrong key: the signature check itself must fail.
    $this->postJson('/api/v1/auth/oauth/apple', appleBody(appleToken(['email' => 'x@icloud.com', 'email_verified' => 'true'], 'kid-1', $rogue)))
        ->assertStatus(422)->assertJsonPath('error.details.identity_token.0', 'The token signature is invalid.');

    // Unknown kid: refetched once, still unknown.
    $this->postJson('/api/v1/auth/oauth/apple', appleBody(appleToken(['email' => 'x@icloud.com', 'email_verified' => 'true'], 'kid-9')))
        ->assertStatus(422)->assertJsonPath('error.details.identity_token.0', 'The token is signed with a key Apple does not publish.');

    expect(User::count())->toBe(0);
});

it('refetches the key set once when a token names a kid that arrived after caching', function () {
    Http::fake([
        AppleIdTokenVerifier::JWKS_URL => Http::sequence()
            ->push(['keys' => [appleKeyPair('kid-1')['jwk']]])
            ->push(['keys' => [appleKeyPair('kid-1')['jwk'], appleKeyPair('kid-2')['jwk']]]),
    ]);

    $this->postJson('/api/v1/auth/oauth/apple', appleBody(appleToken(['email' => 'a@icloud.com', 'email_verified' => 'true'], 'kid-1')))->assertOk();
    $this->postJson('/api/v1/auth/oauth/apple', appleBody(appleToken(['sub' => 'other', 'email' => 'b@icloud.com', 'email_verified' => 'true'], 'kid-2')))->assertOk();

    Http::assertSentCount(2);
});

it('reports apple being unreachable as a 503 to retry', function () {
    Http::fake([AppleIdTokenVerifier::JWKS_URL => fn () => throw new ConnectionException('down')]);

    $this->postJson('/api/v1/auth/oauth/apple', appleBody(appleToken(['email' => 'x@icloud.com', 'email_verified' => 'true'])))
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'service_unavailable');
});

it('rejects something that is not a JWT', function () {
    $this->postJson('/api/v1/auth/oauth/apple', appleBody('not-a-token'))
        ->assertStatus(422)->assertJsonPath('error.details.identity_token.0', 'The token is not a JWT.');
});

it('lets an apple account delete itself with a fresh identity token', function () {
    fakeAppleJwks();
    $pair = $this->postJson('/api/v1/auth/oauth/apple', appleBody(appleToken(['email' => 'ana@icloud.com', 'email_verified' => 'true'])))
        ->assertOk()->json('data');

    $this->withToken($pair['access_token'])
        ->deleteJson('/api/v1/account', ['apple_identity_token' => appleToken()])
        ->assertOk();

    expect(User::count())->toBe(0);
});

it('refuses deletion with an apple token for a different identity', function () {
    fakeAppleJwks();
    $pair = $this->postJson('/api/v1/auth/oauth/apple', appleBody(appleToken(['email' => 'ana@icloud.com', 'email_verified' => 'true'])))
        ->assertOk()->json('data');

    $this->withToken($pair['access_token'])
        ->deleteJson('/api/v1/account', ['apple_identity_token' => appleToken(['sub' => 'someone-else'])])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['apple_identity_token']]]);

    expect(User::count())->toBe(1);
});
