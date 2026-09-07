<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialProvider;
use App\Exceptions\UnlinkableSocialIdentity;
use App\Http\Controllers\Controller;
use App\Services\Auth\SocialAccountLinker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Facades\Socialite;

class OAuthController extends Controller
{
    /**
     * Supported OAuth providers.
     *
     * @var list<string>
     */
    private const PROVIDERS = ['google'];

    public function __construct(
        private readonly SocialAccountLinker $linker,
    ) {}

    /**
     * Redirect to the OAuth provider's consent screen.
     */
    public function redirect(string $provider): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);

        return Socialite::driver($provider)->redirect();
    }

    /**
     * Google's userinfo reports the flag as `email_verified` on the current
     * endpoint and `verified_email` on the older one; accept either.
     *
     * @param  array<string, mixed>  $raw
     */
    private function emailIsVerified(array $raw): bool
    {
        $flag = $raw['email_verified'] ?? $raw['verified_email'] ?? false;

        return $flag === true || $flag === 'true';
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

        // Accounts are linked purely by address, so the provider must vouch
        // for it: an unverified Google address could otherwise sign in as
        // whoever registered that address with a password.
        // Every Socialite driver returns an AbstractUser; the contract alone
        // does not expose the raw payload the flag lives in.
        $raw = $oauthUser instanceof AbstractUser ? $oauthUser->getRaw() : [];

        if (! $this->emailIsVerified($raw)) {
            return redirect()->route('login')
                ->withErrors(['email' => 'Adresa de email a contului Google nu este verificată.']);
        }

        try {
            $user = $this->linker->link(
                SocialProvider::Google,
                (string) $oauthUser->getId(),
                $email,
                $oauthUser->getName() ?? $oauthUser->getNickname(),
            );
        } catch (UnlinkableSocialIdentity) {
            return redirect()->route('login')
                ->withErrors(['email' => 'Contul Google nu are o adresă de email.']);
        }

        Auth::login($user, remember: true);

        return redirect()->intended(
            $user->onboarding_completed ? route('dashboard') : route('onboarding')
        );
    }
}
