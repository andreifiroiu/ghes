<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * A user's messages in one chat context, seeded with the welcome message on
 * first open.
 *
 * The web pages and the API read the thread through here so a change to the
 * seeding cannot leave one of them opening on an empty screen.
 */
class ChatThread
{
    public const CONTEXT_ONBOARDING = 'onboarding';

    public const CONTEXT_PROFILE_UPDATE = 'profile_update';

    public function __construct(
        private readonly OnboardingAgent $onboardingAgent,
    ) {}

    /**
     * @return Collection<int, ChatMessage>
     */
    public function messagesFor(User $user, string $context): Collection
    {
        $messages = $user->chatMessages()
            ->where('context', $context)
            ->orderBy('created_at')
            ->get();

        if ($messages->isNotEmpty()) {
            return $messages;
        }

        $welcome = $user->chatMessages()->create([
            'role' => 'assistant',
            'content' => $this->welcomeFor($context),
            'context' => $context,
        ]);

        return new Collection([$welcome]);
    }

    private function welcomeFor(string $context): string
    {
        return match ($context) {
            self::CONTEXT_PROFILE_UPDATE => 'Salut! Spune-mi ce s-a schimbat — ce să adaug, să scot sau să ajustez în preferințele tale.',
            default => $this->onboardingAgent->welcomeMessage(),
        };
    }
}
