<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users'],
            // `string` matters: without it a numeric JSON body compares by
            // value, and `9` satisfies min:8.
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
