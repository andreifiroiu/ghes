<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
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
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * The credentials to hand to the guard. `remember` is a flag, not a
     * column, so it must never reach `Auth::attempt()` as a where clause.
     *
     * @return array{email: string, password: string}
     */
    public function credentials(): array
    {
        /** @var array{email: string, password: string} $credentials */
        $credentials = $this->safe()->only(['email', 'password']);

        return $credentials;
    }

    /**
     * Deliberately not a validation rule. `boolean()` is total — it reads
     * the HTML checkbox's "on" as true and maps anything it does not
     * recognise to false — whereas a `boolean` rule rejects "on" and a null,
     * and a rejected checkbox is a login that bounces with no message the
     * user can act on. A flag that cannot be malformed does not need a rule.
     */
    public function shouldRemember(): bool
    {
        return $this->boolean('remember');
    }
}
