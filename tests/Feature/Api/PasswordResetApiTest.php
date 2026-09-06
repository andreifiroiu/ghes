<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use App\Services\Auth\PasswordResetter;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

/**
 * @return array<string, mixed>
 */
function signInPair(User $user): array
{
    return test()->postJson('/api/v1/auth/login', [
        'email' => $user->email, 'password' => 'password', 'device_name' => 'phone', 'platform' => 'ios',
    ])->assertOk()->json('data');
}

describe('forgot', function () {
    it('mails the reset link and answers the same for an unknown address', function () {
        Notification::fake();
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email])
            ->assertOk()
            ->assertExactJson(['data' => ['message' => PasswordResetter::LINK_MESSAGE]]);
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nobody@example.test'])
            ->assertOk()
            ->assertExactJson(['data' => ['message' => PasswordResetter::LINK_MESSAGE]]);

        Notification::assertSentTo($user, ResetPasswordNotification::class);
        Notification::assertCount(1);
    });

    it('validates the address', function () {
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nope'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['email']]]);
    });
});

describe('reset', function () {
    it('sets the new password and revokes every device pair', function () {
        $user = User::factory()->create();
        $pair = signInPair($user);
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/password/reset', [
            'token' => $token, 'email' => $user->email, 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123',
        ])->assertOk()->assertJsonStructure(['data' => ['message']]);

        expect(Hash::check('new-password-123', (string) $user->fresh()->password))->toBeTrue()
            ->and($user->tokens()->count())->toBe(0);

        app('auth')->forgetGuards();
        $this->withToken($pair['access_token'])->getJson('/api/v1/profile')->assertStatus(401);
    });

    it('rejects a bad token and an unknown address with one message', function () {
        $user = User::factory()->create(['password' => 'original-pw']);

        $this->postJson('/api/v1/auth/password/reset', [
            'token' => 'bad', 'email' => $user->email, 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123',
        ])->assertStatus(422)->assertJsonPath('error.details.email.0', PasswordResetter::INVALID_LINK_MESSAGE);

        $this->postJson('/api/v1/auth/password/reset', [
            'token' => 'bad', 'email' => 'nobody@example.test', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123',
        ])->assertStatus(422)->assertJsonPath('error.details.email.0', PasswordResetter::INVALID_LINK_MESSAGE);

        expect(Hash::check('original-pw', (string) $user->fresh()->password))->toBeTrue();
    });

    it('applies the registration password rule', function () {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/password/reset', [
            'token' => $token, 'email' => $user->email, 'password' => 'short', 'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['password']]]);
    });
});

describe('verification', function () {
    it('resends the verification mail with the mobile intent', function () {
        Notification::fake();
        $user = User::factory()->create(['email_verified_at' => null]);
        $pair = signInPair($user);

        $this->withToken($pair['access_token'])
            ->postJson('/api/v1/auth/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('data.verified', false);

        Notification::assertSentTo($user, VerifyEmailNotification::class, function (VerifyEmailNotification $n) use ($user): bool {
            return str_contains($n->toMail($user)->actionUrl, 'intent=mobile');
        });
    });

    it('says so when the address is already verified, without mailing', function () {
        Notification::fake();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $pair = signInPair($user);

        $this->withToken($pair['access_token'])
            ->postJson('/api/v1/auth/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('data.verified', true);

        Notification::assertNothingSent();
    });

    it('throttles resends per user', function () {
        Notification::fake();
        config(['eventpulse.api.throttle.verify_per_minute' => 2]);
        $user = User::factory()->create(['email_verified_at' => null]);
        $pair = signInPair($user);

        $this->withToken($pair['access_token'])->postJson('/api/v1/auth/email/verification-notification')->assertOk();
        $this->withToken($pair['access_token'])->postJson('/api/v1/auth/email/verification-notification')->assertOk();
        $this->withToken($pair['access_token'])->postJson('/api/v1/auth/email/verification-notification')->assertStatus(429);
    });
});
