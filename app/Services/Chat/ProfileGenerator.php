<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Enums\EventCategory;
use App\Models\User;
use App\Services\Anthropic\AnthropicClient;
use Illuminate\Support\Facades\Log;

class ProfileGenerator
{
    public function __construct(
        private readonly AnthropicClient $client,
    ) {}

    /**
     * Analyse a user's chat (onboarding or profile-update) and produce a
     * structured interest profile.
     *
     * @return array<string, mixed> Keys are category names (e.g. "music") or tag names
     *                              (e.g. "tag:jazz"), values are float scores 0.0–1.0.
     *                              May also include "city", "price_sensitive", "preferred_times".
     */
    public function generateFromChat(User $user, string $context = 'onboarding'): array
    {
        $messages = $user->chatMessages()
            ->where('context', $context)
            ->orderBy('created_at')
            ->get();

        if ($messages->isEmpty()) {
            return [];
        }

        $transcript = $messages
            ->map(fn ($msg) => ($msg->role === 'user' ? 'User' : 'Assistant').": {$msg->content}")
            ->implode("\n\n");

        try {
            $result = $this->client->sendMessage(
                systemPrompt: (string) config('eventpulse.llm.profile_generation_prompt'),
                userMessage: $transcript,
                operation: 'profile_generation',
                logMetadata: ['user_id' => $user->id],
            );

            return $this->parseProfileResponse($result['content']);
        } catch (\Throwable $e) {
            Log::error('Profile generation failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Merge two profiles, averaging overlapping scores.
     *
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $new
     * @return array<string, mixed>
     */
    public function mergeProfiles(array $existing, array $new): array
    {
        $merged = $existing;

        foreach ($new as $key => $value) {
            if (! is_numeric($value)) {
                $merged[$key] = $value;

                continue;
            }

            $value = (float) $value;

            if (isset($merged[$key]) && is_numeric($merged[$key])) {
                $merged[$key] = max(0.0, min(1.0, ((float) $merged[$key] + $value) / 2));
            } else {
                $merged[$key] = max(0.0, min(1.0, $value));
            }
        }

        return $merged;
    }

    /**
     * Parse Claude's profile JSON response, extracting and clamping scores.
     *
     * @return array<string, mixed>
     */
    private function parseProfileResponse(string $responseText): array
    {
        $json = trim($responseText);

        // Strip markdown code fences if present
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $json, $matches)) {
            $json = $matches[1];
        }

        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Log::warning('Profile generation returned invalid JSON', [
                'raw' => mb_substr($responseText, 0, 500),
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if (! is_array($data)) {
            return [];
        }

        $profile = [];
        $validCategories = array_map(
            fn (EventCategory $c) => $c->value,
            EventCategory::cases(),
        );

        foreach ($data as $key => $value) {
            $normKey = mb_strtolower((string) $key);

            // Non-numeric metadata fields — pass through
            if (in_array($normKey, ['city', 'price_sensitive', 'preferred_times'], true)) {
                $profile[$normKey] = $value;

                continue;
            }

            if (! is_numeric($value)) {
                continue;
            }

            $score = max(0.0, min(1.0, (float) $value));

            // Category scores
            if (in_array($normKey, $validCategories, true)) {
                $profile[$normKey] = $score;

                continue;
            }

            // Tag scores — ensure "tag:" prefix
            $tagKey = str_starts_with($normKey, 'tag:') ? $normKey : "tag:{$normKey}";
            $profile[$tagKey] = $score;
        }

        return $profile;
    }
}
