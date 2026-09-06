<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResetPasswordRequest;
use App\Services\Auth\PasswordResetter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NewPasswordController extends Controller
{
    public function __construct(
        private readonly PasswordResetter $passwords,
    ) {}

    public function create(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    public function store(ResetPasswordRequest $request): RedirectResponse
    {
        /** @var array{token: string, email: string, password: string} $validated */
        $validated = $request->validated();

        $error = $this->passwords->reset($validated);

        if ($error !== null) {
            return back()->withErrors(['email' => $error])->onlyInput('email');
        }

        return redirect()->route('login')->with('success', 'Parola a fost schimbată. Intră în cont cu noua parolă.');
    }
}
