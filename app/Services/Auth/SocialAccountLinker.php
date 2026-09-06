<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * The one identity rule for a social sign-in, shared by the web callback and
 * the native token exchange: an account is matched by email, and created
 * with the address already verified, onboarding pending, and a password the
 * user does not know (they sign in through the provider).
 *
 * Matching purely by email is only safe because both callers demand that the
 * provider vouches for the address first — an unverified Google address must
 * never link to an existing password account.
 */
class SocialAccountLinker
{
    public function findOrCreate(string $email, ?string $name): User
    {
        // Case-insensitive on purpose. Nothing normalises addresses at
        // registration, so a password account holding `Ana@Gmail.com` must
        // still be the account Google's lowercase `ana@gmail.com` links to —
        // otherwise the person ends up with two accounts and a password that
        // opens neither. Postgres compares bytes; LOWER() works on sqlite too.
        $user = User::whereRaw('LOWER(email) = ?', [strtolower($email)])->first();

        if ($user !== null) {
            return $user;
        }

        $user = User::create([
            'name' => $name !== null && $name !== '' ? $name : $email,
            'email' => $email,
            'password' => Str::random(40), // hashed by the model cast
            'onboarding_completed' => false,
        ]);

        // Not mass-assignable on purpose — verified state never arrives from
        // a request. The old callback passed it to create() and it was
        // silently dropped, so Google-created accounts were never marked
        // verified despite Google having vouched for the address.
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }
}
