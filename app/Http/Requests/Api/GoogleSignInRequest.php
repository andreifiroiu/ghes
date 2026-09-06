<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\DTOs\DeviceContext;
use Illuminate\Foundation\Http\FormRequest;

class GoogleSignInRequest extends FormRequest
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
            'id_token' => ['required', 'string', 'max:4096'],
            ...DeviceContext::rules(),
        ];
    }
}
