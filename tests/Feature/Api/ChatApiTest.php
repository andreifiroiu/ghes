<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/**
 * Fake the next Claude replies, in order. One registration per test: a
 * second Http::fake() would not override the first stub for the same URL.
 */
function fakeClaude(string ...$texts): void
{
    $sequence = Http::sequence();

    foreach ($texts as $text) {
        $sequence->push([
            'content' => [['type' => 'text', 'text' => $text]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
        ]);
    }

    Http::fake(['api.anthropic.com/v1/messages' => $sequence]);
}

describe('onboarding', function () {
    it('seeds the welcome message on first open, exactly like the web page', function () {
        $user = User::factory()->create(['onboarding_completed' => false]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/v1/onboarding')->assertOk();

        expect($response->json('data.messages'))->toHaveCount(1)
            ->and($response->json('data.messages.0.role'))->toBe('assistant')
            ->and($response->json('data.onboarding_complete'))->toBeFalse();

        // Opening the web page afterwards must not seed a second welcome.
        $this->withoutVite()->actingAs($user)->get('/onboarding')->assertOk();
        expect($user->chatMessages()->where('context', 'onboarding')->count())->toBe(1);
    });

    it('exchanges a message with the assistant', function () {
        fakeClaude('Sună bine! Ce gen de muzică?');
        $user = User::factory()->create(['onboarding_completed' => false]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/v1/onboarding/chat', ['message' => 'Îmi place jazzul'])
            ->assertOk()
            ->assertJsonPath('data.user_message.content', 'Îmi place jazzul')
            ->assertJsonPath('data.assistant_message.content', 'Sună bine! Ce gen de muzică?')
            ->assertJsonPath('data.onboarding_complete', false)
            ->assertJsonMissingPath('userMessage');
    });

    it('validates the message', function () {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->postJson('/api/v1/onboarding/chat', ['message' => ''])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['message']]]);
    });

    it('refuses to confirm before the chat yields a profile', function () {
        fakeClaude('{}');
        $user = User::factory()->create(['onboarding_completed' => false]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/v1/onboarding/confirm')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['conversation']]]);

        expect($user->fresh()->onboarding_completed)->toBeFalse();
    });

    it('confirms the profile without a redirect target', function () {
        fakeClaude(json_encode(['music' => 0.9, 'summary' => 'Îți place muzica live.']));
        // An empty starting profile: the generator merges into whatever the
        // user already has, and the factory seeds a random one.
        $user = User::factory()->create(['onboarding_completed' => false, 'interest_profile' => []]);
        $user->chatMessages()->create(['role' => 'user', 'content' => 'jazz', 'context' => 'onboarding']);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/v1/onboarding/confirm')
            ->assertOk()
            ->assertJsonPath('data.profile.music', 0.9)
            ->assertJsonPath('data.city_notice', null)
            ->assertJsonMissingPath('data.redirectTo')
            ->assertJsonMissingPath('redirectTo');

        expect($user->fresh()->onboarding_completed)->toBeTrue();
    });
});

describe('profile chat', function () {
    it('seeds the profile-update welcome on first open', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/v1/profile/chat')->assertOk();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.context'))->toBe('profile_update');
    });

    it('exchanges a message and applies the inferred changes', function () {
        fakeClaude('Am notat.', json_encode(['sports' => 0.8]));
        $user = User::factory()->create(['interest_profile' => ['music' => 0.5]]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/v1/profile/chat', ['message' => 'Mai mult sport'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['user_message', 'assistant_message']]);

        $response = $this->postJson('/api/v1/profile/chat/apply')
            ->assertOk()
            ->assertJsonMissingPath('redirectTo');

        expect($response->json('data.profile'))->toHaveKey('sports')
            ->and($response->json('data.profile.sports'))->toBeGreaterThan(0);
    });

    it('reports no detectable change as a validation failure', function () {
        fakeClaude('{}');
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/v1/profile/chat/apply')
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['conversation']]]);
    });
});

it('throttles chat posts per user', function () {
    config(['eventpulse.api.throttle.chat_per_minute' => 2]);
    fakeClaude('ok');
    Sanctum::actingAs(User::factory()->create(), ['*']);

    $this->postJson('/api/v1/onboarding/chat', ['message' => 'a'])->assertOk();
    $this->postJson('/api/v1/onboarding/chat', ['message' => 'b'])->assertOk();
    $this->postJson('/api/v1/onboarding/chat', ['message' => 'c'])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited');
});

it('caps chat posts per day independently of the minute window', function () {
    config(['eventpulse.api.throttle.chat_per_minute' => 20, 'eventpulse.api.throttle.chat_per_day' => 2]);
    fakeClaude('ok', 'ok', 'ok');
    Sanctum::actingAs(User::factory()->create(), ['*']);

    $this->postJson('/api/v1/onboarding/chat', ['message' => 'a'])->assertOk();
    $this->postJson('/api/v1/onboarding/chat', ['message' => 'b'])->assertOk();
    $this->postJson('/api/v1/onboarding/chat', ['message' => 'c'])->assertStatus(429);
});

it('counts each chat post once against the minute window', function () {
    config(['eventpulse.api.throttle.chat_per_minute' => 3, 'eventpulse.api.throttle.chat_per_day' => 200]);
    fakeClaude('ok', 'ok', 'ok', 'ok');
    Sanctum::actingAs(User::factory()->create(), ['*']);

    foreach (['a', 'b', 'c'] as $message) {
        $this->postJson('/api/v1/onboarding/chat', ['message' => $message])->assertOk();
    }

    $this->postJson('/api/v1/onboarding/chat', ['message' => 'd'])->assertStatus(429);
});

it('leaves the web chat JSON unchanged', function () {
    fakeClaude('Sună bine!');
    $user = User::factory()->create(['onboarding_completed' => false]);

    $this->actingAs($user)->postJson('/onboarding/chat', ['message' => 'salut'])
        ->assertOk()
        ->assertJsonStructure(['userMessage', 'assistantMessage', 'onboardingComplete']);
});
