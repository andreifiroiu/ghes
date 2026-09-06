<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Services\Auth\PasswordResetter;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    public function __construct(
        private readonly PasswordResetter $passwords,
    ) {}

    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    public function store(ForgotPasswordRequest $request): RedirectResponse
    {
        /** @var array{email: string} $validated */
        $validated = $request->validated();

        $this->passwords->sendLink($validated['email']);

        return back()->with('success', PasswordResetter::LINK_MESSAGE);
    }
}
