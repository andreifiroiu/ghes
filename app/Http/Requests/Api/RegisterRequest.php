<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\DTOs\DeviceContext;
use App\Services\City\CityCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            // `string` matters: a numeric JSON body would otherwise satisfy
            // min:8 by value.
            'password' => ['required', 'string', 'min:8'],
            // Same catalogue the profile endpoint enforces — the two used to
            // disagree, so a client could register a city that PUT /profile
            // would then reject forever.
            'city' => ['sometimes', 'nullable', 'string', Rule::in(CityCatalog::labels())],
            ...DeviceContext::rules(),
        ];
    }
}
