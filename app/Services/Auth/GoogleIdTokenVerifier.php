<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\DTOs\GoogleIdentity;
use App\Exceptions\InvalidGoogleIdToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Verifies a Google ID token the native app obtained through PKCE.
 *
 * Google's tokeninfo endpoint validates the signature for us, so this is a
 * round-trip per sign-in instead of a JWT/JWKS implementation — sign-in is
 * not a hot path, and the seam is trivially fakeable. What tokeninfo does
 * not do is check that the token was minted *for us* or that the address is
 * one Google vouches for; those checks are here, and the second is what
 * stops an unverified Google address from taking over a password account
 * that happens to share it.
 */
class GoogleIdTokenVerifier
{
    public const TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo';

    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    /**
     * @throws InvalidGoogleIdToken when the token fails a check
     * @throws HttpException 503 when Google cannot be reached — not the
     *                       token's fault, and the client should retry
     */
    public function verify(string $idToken): GoogleIdentity
    {
        try {
            $response = Http::timeout(10)->get(self::TOKENINFO_URL, ['id_token' => $idToken]);
        } catch (ConnectionException $e) {
            throw new HttpException(503, 'Google sign-in is temporarily unavailable.', $e, ['Retry-After' => '30']);
        }

        if (! $response->successful()) {
            throw new InvalidGoogleIdToken('Google did not accept the token.');
        }

        /** @var array<string, mixed> $claims */
        $claims = $response->json();

        if (! in_array($claims['iss'] ?? null, self::ISSUERS, true)) {
            throw new InvalidGoogleIdToken('The token was not issued by Google.');
        }

        if (! in_array($claims['aud'] ?? null, $this->clientIds(), true)) {
            throw new InvalidGoogleIdToken('The token was not issued for this app.');
        }

        if ((int) ($claims['exp'] ?? 0) <= time()) {
            throw new InvalidGoogleIdToken('The token has expired.');
        }

        // tokeninfo returns every claim as a string.
        if (($claims['email_verified'] ?? null) !== 'true') {
            throw new InvalidGoogleIdToken('The Google account email is not verified.');
        }

        $email = $claims['email'] ?? null;
        $subject = $claims['sub'] ?? null;

        if (! is_string($email) || $email === '' || ! is_string($subject) || $subject === '') {
            throw new InvalidGoogleIdToken('The token carries no usable identity.');
        }

        $name = $claims['name'] ?? null;

        return new GoogleIdentity(
            subject: $subject,
            email: $email,
            name: is_string($name) && $name !== '' ? $name : null,
        );
    }

    /**
     * Every OAuth client id a token may be minted for: the iOS, Android and
     * web clients each have their own.
     *
     * @return list<string>
     */
    private function clientIds(): array
    {
        return array_values(array_filter(
            array_map('trim', (array) config('services.google.client_ids', [])),
            fn (string $id): bool => $id !== '',
        ));
    }
}
