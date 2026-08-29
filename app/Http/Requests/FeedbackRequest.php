<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Reaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FeedbackRequest extends FormRequest
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
            'event_id' => ['required', 'uuid', 'exists:events,id'],
            'reaction' => ['required', Rule::enum(Reaction::class)],
        ];
    }
}
