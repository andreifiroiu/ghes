<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Anthropic\AnthropicClient;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class OnboardingAgent
{
    public function __construct(
        private readonly AnthropicClient $client,
    ) {}

    /**
     * Process a user message and return the assistant's response.
     *
     * The controller is responsible for saving messages; this service only
     * generates the response by calling the Claude API with full history.
     */
    public function respond(User $user, string $userMessage): string
    {
        $history = $this->loadHistory($user);

        // Append the latest user message (already saved by the controller)
        $messages = $this->buildApiMessages($history);

        try {
            $result = $this->client->sendMessage(
                systemPrompt: (string) config('eventpulse.llm.onboarding_system_prompt'),
                userMessage: $this->formatConversation($messages),
                operation: 'onboarding_chat',
                logMetadata: ['user_id' => $user->id],
            );

            return $result['content'];
        } catch (\Throwable $e) {
            Log::error('Onboarding chat failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return 'Îmi pare rău, am întâmpinat o problemă. Poți încerca din nou?';
        }
    }

    /**
     * Send a multi-turn conversation to Claude using the messages API.
     *
     * Unlike `respond()` which flattens history into a single user message,
     * this builds proper alternating user/assistant turns.
     */
    public function chat(User $user, string $userMessage): string
    {
        $history = $this->loadHistory($user);

        $apiMessages = $this->buildApiMessages($history);

        try {
            $response = $this->client->sendMultiTurn(
                systemPrompt: (string) config('eventpulse.llm.onboarding_system_prompt'),
                messages: $apiMessages,
                operation: 'onboarding_chat',
                logMetadata: ['user_id' => $user->id],
            );

            return $response['content'];
        } catch (\Throwable $e) {
            Log::error('Onboarding chat failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return 'Îmi pare rău, am întâmpinat o problemă. Poți încerca din nou?';
        }
    }

    /**
     * Check whether the conversation has gathered enough information.
     *
     * Returns true if:
     * - User has sent at least `min_exchanges` messages, AND
     * - The latest assistant message contains the [PROFILE_READY] marker
     */
    public function isOnboardingComplete(User $user): bool
    {
        $minExchanges = (int) config('eventpulse.onboarding.min_exchanges', 4);

        $userMessageCount = $user->chatMessages()
            ->where('context', 'onboarding')
            ->where('role', 'user')
            ->count();

        if ($userMessageCount < $minExchanges) {
            return false;
        }

        $lastAssistant = $user->chatMessages()
            ->where('context', 'onboarding')
            ->where('role', 'assistant')
            // chat_messages carries timestamp(0), so messages seconds apart can
            // share a created_at. UUIDv7 ids break the tie in write order.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $lastAssistant !== null
            && str_contains($lastAssistant->content, '[PROFILE_READY]');
    }

    /**
     * Get the welcome message for a new onboarding session.
     */
    public function welcomeMessage(): string
    {
        return (string) config('eventpulse.onboarding.welcome_message');
    }

    /**
     * @return Collection<int, ChatMessage>
     */
    private function loadHistory(User $user): Collection
    {
        return $user->chatMessages()
            ->where('context', 'onboarding')
            // chat_messages carries timestamp(0), so messages seconds apart can
            // share a created_at. UUIDv7 ids break the tie in write order.
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Build the API messages array from chat history.
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function buildApiMessages(Collection $history): array
    {
        return $history->map(fn (ChatMessage $msg) => [
            'role' => $msg->role,
            'content' => $msg->content,
        ])->toArray();
    }

    /**
     * Format conversation as a single text block for the single-turn API.
     */
    private function formatConversation(array $messages): string
    {
        return collect($messages)
            ->map(fn (array $m) => ($m['role'] === 'user' ? 'User' : 'Assistant').": {$m['content']}")
            ->implode("\n\n");
    }
}
