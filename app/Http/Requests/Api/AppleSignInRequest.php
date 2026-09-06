<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\DTOs\DeviceContext;
use Illuminate\Foundation\Http\FormRequest;

class AppleSignInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Apple puts the person's name in the sign-in response, not in the
     * token, and only on the first authorisation — the app forwards it.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'identity_token' => ['required', 'string', 'max:8192'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            ...DeviceContext::rules(),
        ];
    }
}
