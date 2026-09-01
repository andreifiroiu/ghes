<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\City\CityCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users')->ignore($this->user()->id)],
            'timezone' => ['sometimes', 'string', 'timezone'],
            // Restricted to the cities Ghes scrapes: a free-text city that no
            // source covers reads as "saved" but leaves the feed empty. The
            // account's own city is allowed through even when it is no longer
            // covered — the form posts every field at once, so rejecting it
            // outright would stop a legacy user editing their name or email.
            'city' => $this->cityRules(),
        ];
    }

    /**
     * The account form posts every field on every save, so `sometimes` never
     * skips the city and its rules decide whether unrelated edits go through.
     *
     * @return list<mixed>
     */
    private function cityRules(): array
    {
        $rules = ['sometimes'];

        // An account with no city may post an empty selection — that state is
        // legitimate and would otherwise be unsaveable and unrepairable. An
        // account that has one must not be able to clear it with a blank field.
        if (blank($this->user()->city)) {
            $rules[] = 'nullable';
        }

        $rules[] = 'string';
        $rules[] = Rule::in($this->allowedCities());

        return $rules;
    }

    /**
     * Covered cities, plus whatever this account already has.
     *
     * @return list<string>
     */
    private function allowedCities(): array
    {
        $current = $this->user()->city;

        return array_values(array_unique(array_merge(
            CityCatalog::labels(),
            filled($current) ? [$current] : [],
        )));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'city.in' => 'Orașul selectat nu este acoperit momentan.',
        ];
    }
}
