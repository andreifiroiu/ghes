<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * One-click opt-out of event reminders, from inside the email itself.
 *
 * The settings page is behind auth and a mail webview usually has no session,
 * so "manage your preferences" is not an unsubscribe link — identity here comes
 * from the URL signature, exactly as it does for the reaction links.
 */
class NotificationUnsubscribeController extends Controller
{
    /**
     * Render the confirmation page for a signed unsubscribe link.
     *
     * Writes nothing, for the same reason the reaction GET writes nothing: mail
     * clients and corporate link scanners fetch every URL in a message, and a
     * mutating GET would silently unsubscribe people who never clicked. The
     * page auto-submits a POST to the same signed URI.
     */
    public function show(Request $request, User $user): Response
    {
        return response()->view('emails.unsubscribe-confirm', [
            'user' => $user,
            'action' => $request->fullUrl(),
        ]);
    }

    /**
     * Turn event reminders off for this account.
     *
     * Scoped to reminders on purpose: the digest is the thing the user signed
     * up for, and silently cancelling it because they were tired of one
     * reminder would be a worse surprise than the reminder was.
     */
    public function store(Request $request, User $user): RedirectResponse
    {
        $user->update(['event_reminders_enabled' => false]);

        return redirect()->route('home', ['unsubscribed' => 'reminders']);
    }
}
