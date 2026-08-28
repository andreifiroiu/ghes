<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Anthropic\AnthropicClient;
use App\Services\Chat\ProfileUpdateAgent;
use Illuminate\Support\Facades\Http;

function makeProfileUpdateAgent(): ProfileUpdateAgent
{
    return new ProfileUpdateAgent(
        client: new AnthropicClient(apiKey: 'test-key', model: 'claude-sonnet-4-20250514'),
    );
}

it('responds using Claude with the profile-update conversation history', function () {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Am notat — îți plac acum vinilurile.']],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
        ]),
    ]);

    $user = User::factory()->create();
    $user->chatMessages()->create([
        'role' => 'user',
        'content' => 'Am început să colecționez viniluri',
        'context' => 'profile_update',
    ]);

    $response = makeProfileUpdateAgent()->respond($user, 'Am început să colecționez viniluri');

    expect($response)->toBe('Am notat — îți plac acum vinilurile.');
});

it('returns a fallback message when Claude fails', function () {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response(['error' => ['message' => 'overloaded']], 529),
    ]);

    $user = User::factory()->create();
    $user->chatMessages()->create([
        'role' => 'user',
        'content' => 'salut',
        'context' => 'profile_update',
    ]);

    $response = makeProfileUpdateAgent()->respond($user, 'salut');

    expect($response)->toContain('pare rău');
});
