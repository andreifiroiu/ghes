<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Http\Resources\UserResource;
use App\Services\City\CityCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function show(Request $request): Response
    {
        return Inertia::render('Dashboard/Profile', [
            'user' => new UserResource($request->user()),
            'cityOptions' => CityCatalog::labels(),
        ]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $emailChanged = isset($validated['email']) && $validated['email'] !== $user->email;

        if ($emailChanged) {
            $validated['email_verified_at'] = null;
        }

        $user->update($validated);

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        return redirect()->route('profile.show')
            ->with('success', 'Profile updated.');
    }

    public function resendVerification(Request $request): RedirectResponse
    {
        $request->user()->sendEmailVerificationNotification();

        return redirect()->route('profile.show')
            ->with('success', 'Verification email sent.');
    }
}
