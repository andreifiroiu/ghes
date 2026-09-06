<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Build the link the verification mail carries, the way the notification does.
 */
function verificationUrlFor(User $user, array $extra = [], ?int $minutes = 60): string
{
    return URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes($minutes),
        ['id' => $user->id, 'hash' => sha1($user->email), ...$extra],
    );
}

describe('sending', function () {
    // Regression: `route('verification.verify')` did not exist, so building
    // the mail threw and both of these paths answered 500 in production.
    it('resends the verification mail from the profile page without a 500', function () {
        Notification::fake();
        $user = User::factory()->create(['email_verified_at' => null]);

        $this->actingAs($user)
            ->post('/profile/resend-verification')
            ->assertRedirect(route('profile.show'));

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    });

    it('throttles the resend endpoint', function () {
        Notification::fake();
        $user = User::factory()->create(['email_verified_at' => null]);

        foreach (range(1, 6) as $i) {
            $this->actingAs($user)->post('/profile/resend-verification')->assertRedirect();
        }

        $this->actingAs($user)->post('/profile/resend-verification')->assertStatus(429);
    });

    it('sends a verification mail when the profile email changes', function () {
        Notification::fake();
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->put('/profile', ['name' => $user->name, 'email' => 'new@example.test', 'city' => $user->city])
            ->assertRedirect(route('profile.show'));

        expect($user->fresh()->email_verified_at)->toBeNull();
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    });

    it('builds a signed link to the verification route, in Romanian, without a mobile intent by default', function () {
        $user = User::factory()->create(['email_verified_at' => null]);

        $mail = (new VerifyEmailNotification)->toMail($user);

        expect($mail->subject)->toContain('Ghes')
            ->and($mail->actionUrl)->toContain('/verify-email/'.$user->id.'/'.sha1($user->email))
            ->and($mail->actionUrl)->toContain('signature=')
            ->and($mail->actionUrl)->not->toContain('intent=');
    });

    it('carries the mobile intent inside the signed link when asked', function () {
        $user = User::factory()->create(['email_verified_at' => null]);

        $mail = (new VerifyEmailNotification(VerifyEmailNotification::INTENT_MOBILE))->toMail($user);

        expect($mail->actionUrl)->toContain('intent=mobile');
        $this->get($mail->actionUrl)->assertRedirect('ghes://verified');
    });
});

describe('the link', function () {
    it('verifies the address and sends a signed-in user to the profile', function () {
        Event::fake([Verified::class]);
        $user = User::factory()->create(['email_verified_at' => null]);

        $this->actingAs($user)
            ->get(verificationUrlFor($user))
            ->assertRedirect(route('profile.show'));

        expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
        Event::assertDispatched(Verified::class);
    });

    it('verifies without a session and sends the reader to sign in', function () {
        $user = User::factory()->create(['email_verified_at' => null]);

        $this->get(verificationUrlFor($user))->assertRedirect(route('login'));

        expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    });

    it('bounces back into the app when the link carries the mobile intent', function () {
        config(['eventpulse.mobile.scheme' => 'ghes']);
        $user = User::factory()->create(['email_verified_at' => null]);

        $this->get(verificationUrlFor($user, ['intent' => 'mobile']))->assertRedirect('ghes://verified');

        expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    });

    it('is idempotent on an already verified address', function () {
        Event::fake([Verified::class]);
        $verifiedAt = now()->subDay()->startOfSecond();
        $user = User::factory()->create(['email_verified_at' => $verifiedAt]);

        $this->get(verificationUrlFor($user))->assertRedirect(route('login'));

        expect($user->fresh()->email_verified_at->equalTo($verifiedAt))->toBeTrue();
        Event::assertNotDispatched(Verified::class);
    });

    it('rejects a tampered or unsigned link', function () {
        $user = User::factory()->create(['email_verified_at' => null]);

        $this->get('/verify-email/'.$user->id.'/'.sha1($user->email))->assertForbidden();
        $this->get(verificationUrlFor($user).'&x=1')->assertForbidden();

        expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    });

    it('rejects an expired link', function () {
        $user = User::factory()->create(['email_verified_at' => null]);
        $url = verificationUrlFor($user, minutes: 1);

        $this->travel(2)->minutes();

        $this->get($url)->assertForbidden();
        expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    });

    it('rejects a link for an address the user has since changed', function () {
        $user = User::factory()->create(['email_verified_at' => null]);
        $url = verificationUrlFor($user);

        $user->update(['email' => 'moved@example.test']);

        $this->get($url)->assertForbidden();
        expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    });

    it('cannot mark someone else verified by intent alone', function () {
        // The intent is inside the signature: adding it by hand breaks it.
        $user = User::factory()->create(['email_verified_at' => null]);

        $this->get(verificationUrlFor($user).'&intent=mobile')->assertForbidden();
    });
});
