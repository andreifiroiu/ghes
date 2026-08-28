<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class OAuthController extends Controller
{
    /**
     * Supported OAuth providers.
     *
     * @var list<string>
     */
    private const PROVIDERS = ['google'];

    /**
     * Redirect to the OAuth provider's consent screen.
     */
    public function redirect(string $provider): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);

        return Socialite::driver($provider)->redirect();
    }

    /**
     * Handle the OAuth callback: find or create the user, then log in.
     */
    public function callback(string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);

        try {
            $oauthUser = Socialite::driver($provider)->user();
        } catch (\Throwable) {
            return redirect()->route('login')
                ->withErrors(['email' => 'Autentificarea cu Google a eșuat. Încearcă din nou.']);
        }

        $email = $oauthUser->getEmail();

        if ($email === null || $email === '') {
            return redirect()->route('login')
                ->withErrors(['email' => 'Contul Google nu are o adresă de email.']);
        }

        $user = User::where('email', $email)->first();

        if ($user === null) {
            $user = User::create([
                'name' => $oauthUser->getName() ?? $oauthUser->getNickname() ?? $email,
                'email' => $email,
                'password' => Str::random(40), // hashed by the model cast; OAuth users sign in via the provider
                'email_verified_at' => now(), // Google has verified the address
                'onboarding_completed' => false,
            ]);
        }

        Auth::login($user, remember: true);

        return redirect()->intended(
            $user->onboarding_completed ? route('dashboard') : route('onboarding')
        );
    }
}
