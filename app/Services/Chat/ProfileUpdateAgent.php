<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Models\User;
use App\Services\Anthropic\AnthropicClient;
use Illuminate\Support\Facades\Log;

class ProfileUpdateAgent
{
    public function __construct(
        private readonly AnthropicClient $client,
    ) {}

    /**
     * Process a user message in the profile-update chat and return the response.
     */
    public function respond(User $user, string $userMessage): string
    {
        $history = $user->chatMessages()
            ->where('context', 'profile_update')
            ->orderBy('created_at')
            ->limit(20)
            ->get();

        $messages = $history->map(fn ($msg) => [
            'role' => $msg->role,
            'content' => $msg->content,
        ])->toArray();

        $systemPrompt = 'Ești Ghes, ajuți utilizatorul să-și rafineze preferințele pentru evenimente. '
            .'Profilul curent: '.json_encode($user->interest_profile ?? []).'. '
            .'Înțelege ce vrea să modifice, confirmă schimbările, apoi răspunde natural. '
            .'Răspunde întotdeauna în română.';

        try {
            $result = $this->client->sendMultiTurn(
                systemPrompt: $systemPrompt,
                messages: $messages,
                operation: 'profile_update_chat',
                logMetadata: ['user_id' => $user->id],
            );

            return $result['content'];
        } catch (\Throwable $e) {
            Log::error('Profile update chat failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return 'Îmi pare rău, am întâmpinat o problemă. Poți încerca din nou?';
        }
    }
}
