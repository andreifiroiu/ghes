<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\EventCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminEventUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('access-admin') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        // Cross-field rules are only meaningful when the field they compare
        // against is part of this (potentially partial) payload.
        $endsAtRules = ['nullable', 'date'];

        if ($this->filled('starts_at')) {
            $endsAtRules[] = 'after:starts_at';
        }

        $priceMaxRules = ['nullable', 'numeric', 'min:0'];

        if ($this->filled('price_min')) {
            $priceMaxRules[] = 'gte:price_min';
        }

        return [
            'title' => ['sometimes', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'category' => ['sometimes', 'string', Rule::in(array_column(EventCategory::cases(), 'value'))],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:50'],
            'venue' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => $endsAtRules,
            'price_min' => ['nullable', 'numeric', 'min:0'],
            'price_max' => $priceMaxRules,
            'is_free' => ['sometimes', 'boolean'],
            'image_url' => ['nullable', 'url', 'max:2048'],
        ];
    }
}
