<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;

it('renders notification mail for every user in Romanian', function () {
    $user = User::factory()->create();

    expect($user->preferredLocale())->toBe('ro');
});

it('translates the framework layout strings so no English is left in the verification mail', function () {
    $user = User::factory()->create(['email_verified_at' => null]);

    app()->setLocale($user->preferredLocale());
    $html = (string) (new VerifyEmailNotification)->toMail($user)->render();

    expect($html)
        ->not->toContain("If you're having trouble")
        ->not->toContain('All rights reserved')
        ->not->toContain('Regards,')
        ->toContain('Dacă nu poți apăsa butonul')
        ->toContain('Toate drepturile rezervate')
        ->toContain('Confirmă adresa de email');
});

it('translates the framework layout strings in the password reset mail too', function () {
    $user = User::factory()->create();

    app()->setLocale($user->preferredLocale());
    $html = (string) (new ResetPasswordNotification('token'))->toMail($user)->render();

    expect($html)
        ->not->toContain("If you're having trouble")
        ->not->toContain('All rights reserved')
        ->toContain('Resetează parola');
});

it('restores the app locale after sending, so the request that triggered the mail is untouched', function () {
    // The locale switch lives inside the notification sender; validation
    // messages, Carbon and the UI of the surrounding request keep theirs.
    app()->setLocale('en');
    $user = User::factory()->create(['email_verified_at' => null]);

    $user->sendEmailVerificationNotification();

    expect(app()->getLocale())->toBe('en');
});
