<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Exceptions\InvalidGoogleIdToken;
use App\Services\Auth\GoogleIdTokenVerifier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Validator;

/**
 * Re-authentication before deleting the account: the current password, or —
 * for accounts that sign in with Google and hold a password they do not
 * know — a fresh Google ID token for the same address.
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
            'current_password' => ['required_without:google_id_token', 'string'],
            'google_id_token' => ['required_without:current_password', 'string', 'max:4096'],
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

                $this->checkGoogleToken($validator);
            },
        ];
    }

    private function checkGoogleToken(Validator $validator): void
    {
        try {
            $identity = app(GoogleIdTokenVerifier::class)->verify((string) $this->input('google_id_token'));
        } catch (InvalidGoogleIdToken $e) {
            $validator->errors()->add('google_id_token', $e->getMessage());

            return;
        }

        // The verifier lowercases the identity; the account's address may not be.
        if ($identity->email !== strtolower((string) $this->user()?->email)) {
            $validator->errors()->add('google_id_token', 'The Google account does not match this account.');
        }
    }
}
