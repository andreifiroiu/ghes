<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\DTOs\AppleIdentity;
use App\Exceptions\InvalidIdToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
        $audiences = is_array($audience) ? $audience : [$audience];

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

        return new AppleIdentity(
            subject: $subject,
            email: is_string($email) && $email !== '' ? strtolower($email) : null,
            // Apple sends the flag as a string or a boolean depending on the
            // flow; both spellings of "yes" count.
            emailVerified: in_array($claims['email_verified'] ?? null, [true, 'true'], true),
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
        if ($fresh) {
            Cache::forget(self::JWKS_CACHE_KEY);
        }

        /** @var list<array<string, mixed>> $keys */
        $keys = Cache::remember(self::JWKS_CACHE_KEY, now()->addHours(6), function (): array {
            try {
                $response = Http::acceptJson()->timeout(10)->get(self::JWKS_URL);
            } catch (ConnectionException $e) {
                throw new HttpException(503, 'Apple sign-in is temporarily unavailable.', $e, ['Retry-After' => '30']);
            }

            if (! $response->successful()) {
                throw new HttpException(503, 'Apple sign-in is temporarily unavailable.', null, ['Retry-After' => '30']);
            }

            $keys = $response->json('keys');

            return is_array($keys) ? array_values($keys) : [];
        });

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
