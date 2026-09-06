<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * The password reset flow, shared by the web pages and the API so the two
 * cannot drift on what a token means or what a reset does.
 */
class PasswordResetter
{
    /**
     * Shown for every request, found or not: the page must not reveal which
     * addresses have an account.
     */
    public const LINK_MESSAGE = 'Dacă adresa are un cont Ghes, ai primit un email cu linkul de resetare.';

    public const INVALID_LINK_MESSAGE = 'Linkul de resetare nu mai este valid. Cere unul nou.';

    /**
     * Send the reset link. The broker's own outcome is deliberately not
     * surfaced — "no such user" and "sent" must look the same to the caller,
     * and "throttled" is a reason to wait, not a reason to tell an attacker
     * the address exists.
     */
    public function sendLink(string $email): void
    {
        Password::sendResetLink(['email' => $email]);
    }

    /**
     * Apply a reset. Returns null on success, or the message to show.
     *
     * @param  array{token: string, email: string, password: string}  $credentials
     */
    public function reset(array $credentials): ?string
    {
        $status = Password::reset($credentials, function (User $user, string $password): void {
            // `password` is a hashed cast; the remember token is rotated so a
            // stolen "remember me" cookie dies with the old password.
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            event(new PasswordReset($user));
        });

        // "No such account" and "bad token" share one message on purpose:
        // the broker checks the user before the token, so a distinct
        // wording would let anyone probe which addresses have an account
        // with a garbage token — the same leak LINK_MESSAGE closes above.
        return match ($status) {
            Password::PASSWORD_RESET => null,
            Password::INVALID_TOKEN,
            Password::INVALID_USER => self::INVALID_LINK_MESSAGE,
            default => 'Nu am putut reseta parola. Încearcă din nou.',
        };
    }
}
