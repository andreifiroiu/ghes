<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\DeleteAccountRequest;
use App\Services\Account\AccountDeleter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Self-service account deletion on the web, the twin of the API's
 * DELETE /api/v1/account and the URL Google Play's data-safety form asks
 * for. Same AccountDeleter, so the two cannot drift on what is removed.
 */
class AccountController extends Controller
{
    public function __construct(
        private readonly AccountDeleter $deleter,
    ) {}

    public function destroy(DeleteAccountRequest $request): RedirectResponse
    {
        $user = $request->user();

        // Sign out before the row goes, so the session guard is not left
        // pointing at a user that no longer exists.
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $this->deleter->delete($user);

        return redirect()->route('home')->with('success', 'Contul tău a fost șters. Ne pare rău să te vedem plecând.');
    }
}
