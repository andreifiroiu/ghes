<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Anthropic\AnthropicClient;
use App\Services\Chat\OnboardingAgent;
use Illuminate\Support\Facades\Http;

function fakeClaudeChatResponse(string $text, int $inputTokens = 150, int $outputTokens = 80): void
{
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response([
            'content' => [['type' => 'text', 'text' => $text]],
            'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens],
        ]),
    ]);
}

function makeOnboardingAgent(): OnboardingAgent
{
    return new OnboardingAgent(
        client: new AnthropicClient(
            apiKey: 'test-key',
            model: 'claude-sonnet-4-20250514',
        ),
    );
}

it('returns a welcome message from config', function () {
    $agent = makeOnboardingAgent();

    $welcome = $agent->welcomeMessage();

    expect($welcome)->toContain('Ghes');
    expect($welcome)->not->toBeEmpty();
});

it('generates a response using Claude API with conversation history', function () {
    fakeClaudeChatResponse('What kinds of music do you enjoy?');

    $user = User::factory()->create();
    // Seed some history
    $user->chatMessages()->create([
        'role' => 'assistant',
        'content' => 'Welcome! What events do you enjoy?',
        'context' => 'onboarding',
    ]);
    $user->chatMessages()->create([
        'role' => 'user',
        'content' => 'I love live music and art exhibitions',
        'context' => 'onboarding',
    ]);

    $response = makeOnboardingAgent()->chat($user, 'I love live music and art exhibitions');

    expect($response)->toBe('What kinds of music do you enjoy?');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && isset($request['messages'])
            && count($request['messages']) >= 2;
    });
});

it('returns a fallback message when Claude API fails', function () {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response(
            ['error' => ['message' => 'overloaded']],
            529,
        ),
    ]);

    $user = User::factory()->create();
    $user->chatMessages()->create([
        'role' => 'user',
        'content' => 'Hello',
        'context' => 'onboarding',
    ]);

    $response = makeOnboardingAgent()->chat($user, 'Hello');

    expect($response)->toContain('pare rău');
});

it('reports onboarding incomplete with fewer than min_exchanges', function () {
    $user = User::factory()->create();

    // Only 2 user messages (min is 4)
    $user->chatMessages()->createMany([
        ['role' => 'user', 'content' => 'msg1', 'context' => 'onboarding'],
        ['role' => 'assistant', 'content' => 'resp1', 'context' => 'onboarding'],
        ['role' => 'user', 'content' => 'msg2', 'context' => 'onboarding'],
        ['role' => 'assistant', 'content' => 'resp2', 'context' => 'onboarding'],
    ]);

    expect(makeOnboardingAgent()->isOnboardingComplete($user))->toBeFalse();
});

it('reports onboarding incomplete when PROFILE_READY marker is absent', function () {
    $user = User::factory()->create();

    // 5 user messages but no marker
    for ($i = 0; $i < 5; $i++) {
        $user->chatMessages()->create([
            'role' => 'user',
            'content' => "message {$i}",
            'context' => 'onboarding',
        ]);
        $user->chatMessages()->create([
            'role' => 'assistant',
            'content' => "response {$i}",
            'context' => 'onboarding',
        ]);
    }

    expect(makeOnboardingAgent()->isOnboardingComplete($user))->toBeFalse();
});

it('reports onboarding complete when enough exchanges and marker present', function () {
    $user = User::factory()->create();

    for ($i = 0; $i < 4; $i++) {
        $user->chatMessages()->create([
            'role' => 'user',
            'content' => "message {$i}",
            'context' => 'onboarding',
        ]);
        $user->chatMessages()->create([
            'role' => 'assistant',
            'content' => "response {$i}",
            'context' => 'onboarding',
        ]);
    }

    // Add the final summary with marker
    $user->chatMessages()->create([
        'role' => 'user',
        'content' => 'That sounds about right!',
        'context' => 'onboarding',
    ]);
    $user->chatMessages()->create([
        'role' => 'assistant',
        'content' => "Great! Here's your profile summary:\n- Music lover\n- Jazz fan\n\n[PROFILE_READY]",
        'context' => 'onboarding',
    ]);

    expect(makeOnboardingAgent()->isOnboardingComplete($user))->toBeTrue();
});

it('ignores messages from profile_update context for onboarding completion', function () {
    $user = User::factory()->create();

    // 5 messages in profile_update context
    for ($i = 0; $i < 5; $i++) {
        $user->chatMessages()->create([
            'role' => 'user',
            'content' => "msg {$i}",
            'context' => 'profile_update',
        ]);
    }

    expect(makeOnboardingAgent()->isOnboardingComplete($user))->toBeFalse();
});
