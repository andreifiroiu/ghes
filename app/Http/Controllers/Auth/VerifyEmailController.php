<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The landing for the link in the verification mail.
 *
 * Signed, not session-bound: the link is routinely opened somewhere other
 * than where the account signed up — a phone's mail client for a desktop
 * account, or a browser for the native app — so requiring the session that
 * requested it would fail exactly the cases the mail exists for. The
 * signature covers the user id and the email hash, which is what identifies
 * the reader.
 */
class VerifyEmailController extends Controller
{
    public function __invoke(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = User::findOrFail($id);

        // The hash in the URL is of the address the mail went to. An address
        // changed since then invalidates the link even though the signature
        // still checks out — the new address has to be confirmed on its own.
        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        if ($request->query('intent') === VerifyEmailNotification::INTENT_MOBILE) {
            return redirect()->away(config('eventpulse.mobile.scheme').'://verified');
        }

        if ($request->user()?->is($user)) {
            return redirect()->route('profile.show')->with('success', 'Adresa de email a fost confirmată.');
        }

        return redirect()->route('login')->with('success', 'Adresa de email a fost confirmată. Intră în cont.');
    }
}
