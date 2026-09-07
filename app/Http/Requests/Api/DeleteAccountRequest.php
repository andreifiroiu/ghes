<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Enums\SocialProvider;
use App\Exceptions\InvalidIdToken;
use App\Services\Auth\AppleIdTokenVerifier;
use App\Services\Auth\GoogleIdTokenVerifier;
use App\Services\Auth\SocialAccountLinker;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Validator;

/**
 * Re-authentication before deleting the account: the current password, or —
 * for accounts that sign in with a provider and hold a password they do not
 * know — a fresh ID token from that provider for the linked identity.
 */
class DeleteAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required_without_all:google_id_token,apple_identity_token', 'string'],
            'google_id_token' => ['required_without_all:current_password,apple_identity_token', 'string', 'max:4096'],
            'apple_identity_token' => ['required_without_all:current_password,google_id_token', 'string', 'max:8192'],
        ];
    }

    /**
     * Checked here rather than with the `current_password` rule, which
     * validates through a session guard the API does not have.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->filled('current_password')) {
                    if (! Hash::check((string) $this->input('current_password'), (string) $this->user()?->password)) {
                        $validator->errors()->add('current_password', 'The password is incorrect.');
                    }

                    return;
                }

                if ($this->filled('google_id_token')) {
                    $this->checkProviderToken($validator, 'google_id_token', SocialProvider::Google);

                    return;
                }

                $this->checkProviderToken($validator, 'apple_identity_token', SocialProvider::Apple);
            },
        ];
    }

    /**
     * The token must verify and its subject must be one of this account's
     * linked identities — or, for a provider account that signed in before
     * identities were stored, carry this account's address.
     */
    private function checkProviderToken(Validator $validator, string $field, SocialProvider $provider): void
    {
        try {
            [$subject, $email] = match ($provider) {
                SocialProvider::Google => (function (): array {
                    $identity = app(GoogleIdTokenVerifier::class)->verify((string) $this->input('google_id_token'));

                    return [$identity->subject, $identity->email];
                })(),
                SocialProvider::Apple => (function (): array {
                    $identity = app(AppleIdTokenVerifier::class)->verify((string) $this->input('apple_identity_token'));

                    return [$identity->subject, $identity->email];
                })(),
            };
        } catch (InvalidIdToken $e) {
            $validator->errors()->add($field, $e->getMessage());

            return;
        }

        $user = $this->user();

        $linked = $user !== null && app(SocialAccountLinker::class)->belongsTo($user, $provider, $subject);
        $sameAddress = $email !== null && $user !== null && $email === strtolower((string) $user->email);

        if (! $linked && ! $sameAddress) {
            $validator->errors()->add($field, 'The provider account does not match this account.');
        }
    }
}
