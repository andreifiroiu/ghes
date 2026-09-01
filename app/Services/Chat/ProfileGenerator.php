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
     *                              May also include "city", "price_sensitive",
     *                              "preferred_times" and "summary".
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

        $transcript = $this->withSummaryContext($transcript, $user, $context);

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
     * Seed a refinement with the summary it is refining.
     *
     * There is one profile-generation prompt for both contexts, and it opens
     * with "Analyse the following onboarding conversation". A profile-update
     * transcript is not that: it is a welcome line and one correction. Left
     * alone, the model dutifully summarises *the correction* — and that
     * delta-only paragraph would then overwrite the full onboarding recap,
     * shrinking the user's summary a little more with every refinement.
     */
    private function withSummaryContext(string $transcript, User $user, string $context): string
    {
        if ($context !== 'profile_update' || blank($user->profile_summary)) {
            return $transcript;
        }

        return "This user already has a profile summary:\n{$user->profile_summary}\n\n"
            .'The conversation below refines it. The "summary" you return must be the updated '
            .'whole — the existing summary with these refinements folded in — never a summary of '
            ."the conversation on its own.\n\n"
            .$transcript;
    }

    /**
     * The recap the user actually saw at the end of the chat.
     *
     * The onboarding agent is told to summarise what it learned and close that
     * message with [PROFILE_READY], so the last such assistant message is the
     * summary — the only place it has ever been stored. Used when the profile
     * generation call comes back without a "summary" of its own, so the card on
     * the profile page is never blank for a user who did finish onboarding.
     */
    public function summaryFromChat(User $user, string $context = 'onboarding'): ?string
    {
        $message = $user->chatMessages()
            ->where('context', $context)
            ->where('role', 'assistant')
            ->where('content', 'like', '%[PROFILE_READY]%')
            // chat_messages carries timestamp(0), so two messages seconds apart
            // can round into the same second and `latest()` alone would pick
            // between them arbitrarily — storing a recap the user has since
            // corrected. Ids are UUIDv7, so they break the tie in write order.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($message === null) {
            return null;
        }

        $summary = trim(str_replace('[PROFILE_READY]', '', $message->content));

        return $summary === '' ? null : $summary;
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

            // The prompt asks for prose, which models routinely answer with a
            // bullet array. Passing that through would look like a summary all
            // the way to `is_string()` in the controller, then vanish — so it
            // is rejected here, where the shape is still visible.
            if ($normKey === 'summary') {
                if (is_string($value) && trim($value) !== '') {
                    $profile['summary'] = trim($value);
                } else {
                    Log::warning('Profile generation returned a summary that is not prose', [
                        'type' => get_debug_type($value),
                    ]);
                }

                continue;
            }

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
