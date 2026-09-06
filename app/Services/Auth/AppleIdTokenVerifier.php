<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\DTOs\AppleIdentity;
use App\Exceptions\InvalidIdToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Verifies an Apple ID token the native app obtained through Sign in with
 * Apple.
 *
 * Unlike Google there is no tokeninfo endpoint: the token is an RS256 JWT
 * signed with a key from Apple's JWKS, so the signature is checked here
 * with the OpenSSL extension. The key set is cached; an unknown `kid`
 * refetches it once, which is how Apple's key rotation is absorbed.
 */
class AppleIdTokenVerifier
{
    public const JWKS_URL = 'https://appleid.apple.com/auth/keys';

    public const ISSUER = 'https://appleid.apple.com';

    private const JWKS_CACHE_KEY = 'apple:jwks';

    /** How often an unknown `kid` may trigger a fresh fetch from Apple. */
    private const JWKS_REFRESH_LIMITER = 'apple:jwks-refresh';

    /**
     * @throws InvalidIdToken when the token fails a check
     * @throws HttpException 503 when Apple's key set cannot be fetched
     */
    public function verify(string $idToken): AppleIdentity
    {
        $parts = explode('.', $idToken);

        if (count($parts) !== 3) {
            throw new InvalidIdToken('The token is not a JWT.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = $this->decodeJson($encodedHeader);
        $claims = $this->decodeJson($encodedPayload);
        $signature = RsaPublicKey::base64UrlDecode($encodedSignature);

        if (($header['alg'] ?? null) !== 'RS256' || ! is_string($header['kid'] ?? null)) {
            throw new InvalidIdToken('The token is not signed the way Apple signs tokens.');
        }

        $pem = $this->publicKeyFor($header['kid']);

        if (openssl_verify($encodedHeader.'.'.$encodedPayload, $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            throw new InvalidIdToken('The token signature is invalid.');
        }

        if (($claims['iss'] ?? null) !== self::ISSUER) {
            throw new InvalidIdToken('The token was not issued by Apple.');
        }

        $audience = $claims['aud'] ?? null;
        $audiences = array_values(array_filter(is_array($audience) ? $audience : [$audience], 'is_string'));

        if (array_intersect($audiences, $this->clientIds()) === []) {
            throw new InvalidIdToken('The token was not issued for this app.');
        }

        if ((int) ($claims['exp'] ?? 0) <= time()) {
            throw new InvalidIdToken('The token has expired.');
        }

        $subject = $claims['sub'] ?? null;

        if (! is_string($subject) || $subject === '') {
            throw new InvalidIdToken('The token carries no subject.');
        }

        $email = $claims['email'] ?? null;
        $email = is_string($email) && $email !== '' ? strtolower($email) : null;

        // Apple sends the flags as strings or booleans depending on the flow;
        // both spellings of "yes" count.
        $emailVerified = in_array($claims['email_verified'] ?? null, [true, 'true'], true);

        // Enforced here, not in the callers: an address the provider does
        // not vouch for must never reach the linker or the deletion
        // re-check, whichever caller forgets.
        if ($email !== null && ! $emailVerified) {
            throw new InvalidIdToken('The Apple account email is not verified.');
        }

        return new AppleIdentity(
            subject: $subject,
            email: $email,
            emailVerified: $emailVerified,
            isPrivateEmail: in_array($claims['is_private_email'] ?? null, [true, 'true'], true),
        );
    }

    /**
     * The PEM for a key id, from the cached key set — refetched once when
     * the id is unknown, which is what a key rotation looks like.
     */
    private function publicKeyFor(string $kid): string
    {
        $jwk = $this->findKey($this->keys(), $kid) ?? $this->findKey($this->keys(fresh: true), $kid);

        if ($jwk === null) {
            throw new InvalidIdToken('The token is signed with a key Apple does not publish.');
        }

        try {
            return RsaPublicKey::pemFromJwk($jwk);
        } catch (InvalidArgumentException $e) {
            throw new InvalidIdToken('Apple published an unusable key: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $keys
     * @return array<string, mixed>|null
     */
    private function findKey(array $keys, string $kid): ?array
    {
        foreach ($keys as $key) {
            if (($key['kid'] ?? null) === $kid) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function keys(bool $fresh = false): array
    {
        /** @var list<array<string, mixed>>|null $cached */
        $cached = Cache::get(self::JWKS_CACHE_KEY);
        $cached = is_array($cached) && $cached !== [] ? $cached : null;

        if (! $fresh && $cached !== null) {
            return $cached;
        }

        // The refresh is reachable by anyone who sends a token naming an
        // unknown `kid`, before any signature check. Without this a stream
        // of garbage tokens would turn every sign-in into an Apple round-trip
        // and eventually get us rate-limited by Apple — the cache would be
        // protecting nothing. A real rotation needs one fetch, not one per
        // request.
        if ($fresh && $cached !== null && ! RateLimiter::attempt(self::JWKS_REFRESH_LIMITER, 1, fn () => true, 300)) {
            return $cached;
        }

        try {
            $response = Http::acceptJson()->timeout(10)->get(self::JWKS_URL);
        } catch (ConnectionException $e) {
            throw new HttpException(503, 'Apple sign-in is temporarily unavailable.', $e, ['Retry-After' => '30']);
        }

        if (! $response->successful()) {
            throw new HttpException(503, 'Apple sign-in is temporarily unavailable.', null, ['Retry-After' => '30']);
        }

        $keys = $response->json('keys');
        $keys = is_array($keys) ? array_values($keys) : [];

        // A 200 without keys is an outage in disguise (interstitial, truncated
        // body): say so as a 503 the client retries, and cache nothing —
        // caching it would lock every Apple sign-in out for six hours.
        if ($keys === []) {
            throw new HttpException(503, 'Apple sign-in is temporarily unavailable.', null, ['Retry-After' => '30']);
        }

        Cache::put(self::JWKS_CACHE_KEY, $keys, now()->addHours(6));

        return $keys;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $encoded): array
    {
        $decoded = json_decode(RsaPublicKey::base64UrlDecode($encoded), true);

        if (! is_array($decoded)) {
            throw new InvalidIdToken('The token is malformed.');
        }

        return $decoded;
    }

    /**
     * The iOS bundle id and any service id the app signs in with.
     *
     * @return list<string>
     */
    private function clientIds(): array
    {
        return array_values(array_filter(
            array_map('trim', (array) config('services.apple.client_ids', [])),
            fn (string $id): bool => $id !== '',
        ));
    }
}
