<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Services\Auth\PasswordResetter;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

beforeEach(fn () => $this->withoutVite());

describe('requesting a link', function () {
    it('renders the forgot-password page for guests', function () {
        $this->get('/forgot-password')->assertOk();
    });

    it('is linked from the login page', function () {
        $this->get('/login')->assertOk();
        expect(file_get_contents(resource_path('js/Pages/Auth/Login.jsx')))->toContain('/forgot-password');
    });

    it('mails a reset link in Romanian pointing at the reset page', function () {
        Notification::fake();
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHas('success', PasswordResetter::LINK_MESSAGE);

        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user): bool {
            $mail = $notification->toMail($user);

            return str_contains($mail->subject, 'Ghes')
                && str_contains($mail->actionUrl, '/reset-password/'.$notification->token)
                && str_contains($mail->actionUrl, 'email='.urlencode($user->email));
        });
    });

    it('answers an unknown address exactly like a known one', function () {
        Notification::fake();

        $this->post('/forgot-password', ['email' => 'nobody@example.test'])
            ->assertRedirect()
            ->assertSessionHas('success', PasswordResetter::LINK_MESSAGE)
            ->assertSessionDoesntHaveErrors();

        Notification::assertNothingSent();
    });

    it('validates the address', function () {
        $this->from('/forgot-password')
            ->post('/forgot-password', ['email' => 'not-an-email'])
            ->assertRedirect('/forgot-password')
            ->assertSessionHasErrors('email');
    });

    it('throttles the request endpoint', function () {
        Notification::fake();

        foreach (range(1, 6) as $i) {
            $this->post('/forgot-password', ['email' => "u{$i}@example.test"])->assertRedirect();
        }

        $this->post('/forgot-password', ['email' => 'u7@example.test'])->assertStatus(429);
    });
});

describe('resetting', function () {
    it('renders the reset page with the token and address from the link', function () {
        $this->get('/reset-password/abc?email=me%40example.test')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Auth/ResetPassword')
                ->where('token', 'abc')
                ->where('email', 'me@example.test'));
    });

    it('sets the new password, rotates the remember token, and sends the user to sign in', function () {
        Event::fake([PasswordReset::class]);
        $user = User::factory()->create(['remember_token' => 'old-token']);
        $token = Password::createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect(route('login'))->assertSessionHas('success');

        $user->refresh();
        expect(Hash::check('new-password-123', (string) $user->password))->toBeTrue()
            ->and($user->remember_token)->not->toBe('old-token');
        Event::assertDispatched(PasswordReset::class);

        // The token is single-use.
        $this->from('/reset-password/'.$token)->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'another-password-1',
            'password_confirmation' => 'another-password-1',
        ])->assertSessionHasErrors('email');
    });

    it('lets the user sign in with the new password', function () {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $this->post('/login', ['email' => $user->email, 'password' => 'new-password-123'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    });

    it('rejects a wrong token with a Romanian message and leaves the password alone', function () {
        $user = User::factory()->create(['password' => 'original-pw']);

        $this->from('/reset-password/bad')->post('/reset-password', [
            'token' => 'bad',
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect('/reset-password/bad')->assertSessionHasErrors(['email' => PasswordResetter::INVALID_LINK_MESSAGE]);

        expect(Hash::check('original-pw', (string) $user->fresh()->password))->toBeTrue();
    });

    it('answers an unknown address exactly like a bad token, so the reset page cannot enumerate accounts', function () {
        $this->from('/reset-password/bad')->post('/reset-password', [
            'token' => 'bad',
            'email' => 'nobody@example.test',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertSessionHasErrors(['email' => PasswordResetter::INVALID_LINK_MESSAGE]);
    });

    it('does not let a numeric body slip under the length rule', function () {
        $user = User::factory()->create(['password' => 'original-pw']);
        $token = Password::createToken($user);

        $this->postJson('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 9,
            'password_confirmation' => 9,
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson('/register', [
            'name' => 'N',
            'email' => 'numeric@example.test',
            'password' => 99999999,
            'password_confirmation' => 99999999,
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    });

    it('rejects a token used with a different address', function () {
        $victim = User::factory()->create(['password' => 'victim-pw']);
        $attacker = User::factory()->create();
        $token = Password::createToken($attacker);

        $this->from('/reset-password/'.$token)->post('/reset-password', [
            'token' => $token,
            'email' => $victim->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertSessionHasErrors('email');

        expect(Hash::check('victim-pw', (string) $victim->fresh()->password))->toBeTrue();
    });

    it('applies the registration password rule', function () {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->from('/reset-password/'.$token)->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->from('/reset-password/'.$token)->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'long-enough-1',
            'password_confirmation' => 'different-1',
        ])->assertSessionHasErrors('password');
    });

    it('keeps the reset pages away from signed-in users', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/forgot-password')->assertRedirect();
        $this->actingAs($user)->get('/reset-password/abc')->assertRedirect();
    });
});
