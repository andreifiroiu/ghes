<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->withoutVite();
});

it('seeds a welcome message on first visit to the profile chat', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/profile/chat')
        ->assertStatus(200)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard/ProfileChat')
            ->has('messages', 1)
        );

    expect($user->chatMessages()->where('context', 'profile_update')->count())->toBe(1);
});

it('stores a profile-update message and returns the assistant reply', function () {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Am notat schimbarea.']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/profile/chat', ['message' => 'M-am apucat de ceramică'])
        ->assertStatus(200)
        ->assertJsonPath('assistantMessage.content', 'Am notat schimbarea.');

    expect($user->chatMessages()->where('context', 'profile_update')->count())->toBe(2);
});

it('applies inferred profile changes from the conversation', function () {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response([
            'content' => [['type' => 'text', 'text' => '{"music": 0.9, "tag:pottery": 0.8}']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);

    $user = User::factory()->create(['interest_profile' => ['music' => 0.5]]);
    $user->chatMessages()->create([
        'role' => 'user',
        'content' => 'M-am apucat de ceramică',
        'context' => 'profile_update',
    ]);

    $this->actingAs($user)
        ->postJson('/profile/chat/apply')
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    $user->refresh();
    expect($user->interest_profile)->toHaveKey('tag:pottery');
});

it('requires authentication for the profile chat', function () {
    $this->get('/profile/chat')->assertRedirect('/login');
});
