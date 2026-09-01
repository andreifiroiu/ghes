<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Anthropic\AnthropicClient;
use App\Services\Chat\ProfileGenerator;
use Illuminate\Support\Facades\Http;

function makeProfileGenerator(): ProfileGenerator
{
    return new ProfileGenerator(
        client: new AnthropicClient(
            apiKey: 'test-key',
            model: 'claude-sonnet-4-20250514',
        ),
    );
}

it('generates a profile from chat history', function () {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode([
                'music' => 0.9,
                'arts' => 0.7,
                'sports' => 0.1,
                'tag:jazz' => 0.85,
                'tag:painting' => 0.6,
                'city' => 'Bucharest',
                'price_sensitive' => true,
                'preferred_times' => ['evening', 'weekend'],
            ])]],
            'usage' => ['input_tokens' => 300, 'output_tokens' => 100],
        ]),
    ]);

    $user = User::factory()->create();
    $user->chatMessages()->createMany([
        ['role' => 'assistant', 'content' => 'What events do you enjoy?', 'context' => 'onboarding'],
        ['role' => 'user', 'content' => 'I love jazz concerts and art galleries', 'context' => 'onboarding'],
        ['role' => 'assistant', 'content' => 'Great taste! Budget preference?', 'context' => 'onboarding'],
        ['role' => 'user', 'content' => 'I prefer free or cheap events', 'context' => 'onboarding'],
    ]);

    $profile = makeProfileGenerator()->generateFromChat($user);

    expect($profile)->toHaveKey('music');
    expect($profile['music'])->toBe(0.9);
    expect($profile)->toHaveKey('tag:jazz');
    expect($profile['tag:jazz'])->toBe(0.85);
    expect($profile)->toHaveKey('city', 'Bucharest');
    expect($profile)->toHaveKey('price_sensitive', true);
});

it('handles markdown-wrapped JSON in response', function () {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response([
            'content' => [['type' => 'text', 'text' => "```json\n{\"music\": 0.8, \"tag:rock\": 0.7}\n```"]],
            'usage' => ['input_tokens' => 200, 'output_tokens' => 50],
        ]),
    ]);

    $user = User::factory()->create();
    $user->chatMessages()->create([
        'role' => 'user',
        'content' => 'I like rock music',
        'context' => 'onboarding',
    ]);

    $profile = makeProfileGenerator()->generateFromChat($user);

    expect($profile['music'])->toBe(0.8);
    expect($profile['tag:rock'])->toBe(0.7);
});

it('clamps scores to 0-1 range', function () {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response([
            'content' => [['type' => 'text', 'text' => '{"music": 1.5, "sports": -0.3}']],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 30],
        ]),
    ]);

    $user = User::factory()->create();
    $user->chatMessages()->create([
        'role' => 'user',
        'content' => 'test',
        'context' => 'onboarding',
    ]);

    $profile = makeProfileGenerator()->generateFromChat($user);

    expect($profile['music'])->toBe(1.0);
    expect($profile['sports'])->toBe(0.0);
});

it('returns empty array when chat history is empty', function () {
    $user = User::factory()->create();

    $profile = makeProfileGenerator()->generateFromChat($user);

    expect($profile)->toBeEmpty();
});

it('returns empty array on Claude API failure', function () {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response(
            ['error' => ['message' => 'overloaded']],
            529,
        ),
    ]);

    $user = User::factory()->create();
    $user->chatMessages()->create([
        'role' => 'user',
        'content' => 'I like music',
        'context' => 'onboarding',
    ]);

    $profile = makeProfileGenerator()->generateFromChat($user);

    expect($profile)->toBeEmpty();
});

it('returns empty array on invalid JSON response', function () {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response([
            'content' => [['type' => 'text', 'text' => 'This is not JSON at all.']],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
        ]),
    ]);

    $user = User::factory()->create();
    $user->chatMessages()->create([
        'role' => 'user',
        'content' => 'I like music',
        'context' => 'onboarding',
    ]);

    $profile = makeProfileGenerator()->generateFromChat($user);

    expect($profile)->toBeEmpty();
});

it('normalises category keys to lowercase', function () {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response([
            'content' => [['type' => 'text', 'text' => '{"Music": 0.9, "SPORTS": 0.4}']],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 30],
        ]),
    ]);

    $user = User::factory()->create();
    $user->chatMessages()->create([
        'role' => 'user',
        'content' => 'test',
        'context' => 'onboarding',
    ]);

    $profile = makeProfileGenerator()->generateFromChat($user);

    expect($profile)->toHaveKey('music');
    expect($profile)->toHaveKey('sports');
    expect($profile)->not->toHaveKey('Music');
});

it('adds tag prefix to unrecognised keys', function () {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response([
            'content' => [['type' => 'text', 'text' => '{"jazz": 0.9, "tag:rock": 0.8}']],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 30],
        ]),
    ]);

    $user = User::factory()->create();
    $user->chatMessages()->create([
        'role' => 'user',
        'content' => 'test',
        'context' => 'onboarding',
    ]);

    $profile = makeProfileGenerator()->generateFromChat($user);

    expect($profile)->toHaveKey('tag:jazz');
    expect($profile)->toHaveKey('tag:rock');
});

it('merges profiles by averaging overlapping scores', function () {
    $generator = makeProfileGenerator();

    $existing = ['music' => 0.6, 'tag:jazz' => 0.4, 'sports' => 0.8];
    $new = ['music' => 0.8, 'tag:jazz' => 1.0, 'technology' => 0.5];

    $merged = $generator->mergeProfiles($existing, $new);

    expect($merged['music'])->toEqualWithDelta(0.7, 0.001);
    expect($merged['tag:jazz'])->toEqualWithDelta(0.7, 0.001);
    expect($merged['sports'])->toBe(0.8); // unchanged
    expect($merged['technology'])->toBe(0.5); // new key
});

it('merges non-numeric metadata fields directly', function () {
    $generator = makeProfileGenerator();

    $existing = ['music' => 0.5, 'city' => 'Bucharest'];
    $new = ['city' => 'Cluj-Napoca', 'price_sensitive' => true];

    $merged = $generator->mergeProfiles($existing, $new);

    expect($merged['city'])->toBe('Cluj-Napoca');
    expect($merged['price_sensitive'])->toBeTrue();
    expect($merged['music'])->toBe(0.5);
});

it('keeps the summary the model writes', function () {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode([
                'music' => 0.9,
                'summary' => 'Îți plac concertele de jazz și galeriile de artă.',
            ])]],
            'usage' => ['input_tokens' => 300, 'output_tokens' => 100],
        ]),
    ]);

    $user = User::factory()->create();
    $user->chatMessages()->create([
        'role' => 'user', 'content' => 'Îmi place jazzul', 'context' => 'onboarding',
    ]);

    $profile = makeProfileGenerator()->generateFromChat($user);

    // Free-text keys are dropped by the parser unless whitelisted — that is
    // what would silently empty the summary card.
    expect($profile['summary'])->toBe('Îți plac concertele de jazz și galeriile de artă.');
});

it('falls back to the recap shown at the end of the chat', function () {
    // Timestamps staggered on purpose: created_at is second-precision, so rows
    // written in one call share it and the ordering under test would be moot.
    $user = User::factory()->create();
    $user->chatMessages()->createMany([
        ['role' => 'assistant', 'content' => 'Un rezumat vechi. [PROFILE_READY]', 'context' => 'onboarding', 'created_at' => now()->subMinutes(3)],
        ['role' => 'user', 'content' => 'da', 'context' => 'onboarding', 'created_at' => now()->subMinutes(2)],
        ['role' => 'assistant', 'content' => "Deci: jazz și teatru.\n[PROFILE_READY]", 'context' => 'onboarding', 'created_at' => now()->subMinute()],
    ]);

    expect(makeProfileGenerator()->summaryFromChat($user))->toBe('Deci: jazz și teatru.');
});

it('has no fallback recap before the profile is ready', function () {
    $user = User::factory()->create();
    $user->chatMessages()->create([
        'role' => 'assistant', 'content' => 'Ce fel de evenimente îți plac?', 'context' => 'onboarding',
    ]);

    expect(makeProfileGenerator()->summaryFromChat($user))->toBeNull();
});

it('seeds a refinement with the summary it is refining', function () {
    // Without this the model summarises the correction alone, and that
    // delta-only paragraph overwrites the full onboarding recap.
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode(['film' => 0.6])]],
            'usage' => ['input_tokens' => 300, 'output_tokens' => 100],
        ]),
    ]);

    $user = User::factory()->create(['profile_summary' => 'Îți plac concertele de jazz.']);
    $user->chatMessages()->create([
        'role' => 'user', 'content' => 'îmi plac și filmele', 'context' => 'profile_update',
    ]);

    makeProfileGenerator()->generateFromChat($user, 'profile_update');

    Http::assertSent(function ($request) {
        $sent = $request->data()['messages'][0]['content'];

        return str_contains($sent, 'Îți plac concertele de jazz.')
            && str_contains($sent, 'never a summary of the conversation on its own');
    });
});

it('does not seed an onboarding generation with the stored summary', function () {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode(['music' => 0.9])]],
            'usage' => ['input_tokens' => 300, 'output_tokens' => 100],
        ]),
    ]);

    $user = User::factory()->create(['profile_summary' => 'Un rezumat vechi.']);
    $user->chatMessages()->create([
        'role' => 'user', 'content' => 'îmi place jazzul', 'context' => 'onboarding',
    ]);

    makeProfileGenerator()->generateFromChat($user);

    Http::assertSent(fn ($request) => ! str_contains(
        $request->data()['messages'][0]['content'], 'Un rezumat vechi.'
    ));
});

it('rejects a summary that is not prose', function () {
    // Models answer "plain prose, no bullet points" with a bullet array often
    // enough that passing it through would look like a summary until the
    // controller's is_string() check dropped it without trace.
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode([
                'music' => 0.9,
                'summary' => ['Îți plac concertele', 'Eviți cluburile'],
            ])]],
            'usage' => ['input_tokens' => 300, 'output_tokens' => 100],
        ]),
    ]);

    $user = User::factory()->create();
    $user->chatMessages()->create([
        'role' => 'user', 'content' => 'jazz', 'context' => 'onboarding',
    ]);

    $profile = makeProfileGenerator()->generateFromChat($user);

    expect($profile)->not->toHaveKey('summary');
    expect($profile['music'])->toBe(0.9);
});

it('picks the last confirmed recap when two share a timestamp', function () {
    // chat_messages is timestamp(0): two messages seconds apart round into the
    // same second, and without a tiebreaker the superseded recap can win.
    $user = User::factory()->create();
    $at = now();

    $user->chatMessages()->create([
        'role' => 'assistant', 'content' => 'Deci: doar jazz. [PROFILE_READY]',
        'context' => 'onboarding', 'created_at' => $at,
    ]);
    $user->chatMessages()->create([
        'role' => 'assistant', 'content' => 'Deci: jazz și teatru. [PROFILE_READY]',
        'context' => 'onboarding', 'created_at' => $at,
    ]);

    expect(makeProfileGenerator()->summaryFromChat($user))->toBe('Deci: jazz și teatru.');
});
