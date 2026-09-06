<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\SocialProvider;
use App\Exceptions\UnlinkableSocialIdentity;
use App\Models\SocialIdentity;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The one identity rule for a social sign-in, shared by the web callback and
 * the native token exchanges.
 *
 * Order of precedence:
 * 1. a stored identity for this provider + subject — survives an address
 *    change at the provider and Apple's private relay addresses;
 * 2. an existing account holding the address the provider vouches for — the
 *    first sign-in of someone who registered with a password; the identity
 *    is stored so step 1 applies from then on;
 * 3. a new account, created with the address verified, onboarding pending,
 *    and a password the user does not know.
 *
 * Step 2 is only safe because every caller demands that the provider vouches
 * for the address first — an unverified address must never link to an
 * existing password account.
 */
class SocialAccountLinker
{
    /**
     * @throws UnlinkableSocialIdentity when the subject is unknown and no address was supplied
     */
    public function link(SocialProvider $provider, string $subject, ?string $email, ?string $name): User
    {
        $email = $email !== null ? strtolower($email) : null;

        try {
            return $this->linkOnce($provider, $subject, $email, $name);
        } catch (UniqueConstraintViolationException) {
            // Two first sign-ins of one subject racing: the loser now finds
            // the identity the winner stored.
            return $this->linkOnce($provider, $subject, $email, $name);
        }
    }

    /**
     * @throws UnlinkableSocialIdentity
     * @throws UniqueConstraintViolationException when another request stored the same identity first
     */
    private function linkOnce(SocialProvider $provider, string $subject, ?string $email, ?string $name): User
    {
        return DB::transaction(function () use ($provider, $subject, $email, $name): User {
            $identity = SocialIdentity::query()
                ->where('provider', $provider)
                ->where('subject', $subject)
                ->first();

            if ($identity !== null) {
                return $identity->user;
            }

            if ($email === null) {
                throw new UnlinkableSocialIdentity('The provider account is not linked and reported no email address.');
            }

            // Case-insensitive on purpose. Nothing normalises addresses at
            // registration, so a password account holding `Ana@Gmail.com`
            // must still be the account the provider's lowercase address
            // links to — otherwise the person ends up with two accounts and
            // a password that opens neither. Postgres compares bytes; LOWER()
            // works on sqlite too.
            $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

            if ($user === null) {
                $user = User::create([
                    'name' => $name !== null && $name !== '' ? $name : $email,
                    'email' => $email,
                    'password' => Str::random(40), // hashed by the model cast
                    'onboarding_completed' => false,
                ]);

                // Not mass-assignable on purpose — verified state never arrives
                // from a request. The old callback passed it to create() and it
                // was silently dropped.
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $user->socialIdentities()->create([
                'provider' => $provider,
                'subject' => $subject,
                'email' => $email,
            ]);

            return $user;
        });
    }

    /**
     * Whether this provider subject belongs to the user — the re-auth check
     * for account deletion.
     */
    public function belongsTo(User $user, SocialProvider $provider, string $subject): bool
    {
        return $user->socialIdentities()
            ->where('provider', $provider)
            ->where('subject', $subject)
            ->exists();
    }
}
