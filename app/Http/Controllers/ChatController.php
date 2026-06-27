<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ChatRequest;
use App\Http\Resources\ChatMessageResource;
use App\Services\Chat\OnboardingAgent;
use App\Services\Chat\ProfileGenerator;
use App\Services\Chat\ProfileUpdateAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
    public function __construct(
        private readonly OnboardingAgent $onboardingAgent,
        private readonly ProfileGenerator $profileGenerator,
        private readonly ProfileUpdateAgent $profileUpdateAgent,
    ) {}

    /**
     * Show the onboarding chat page.
     *
     * On first visit (no messages yet), creates the welcome message.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $messages = $user->chatMessages()
            ->where('context', 'onboarding')
            ->orderBy('created_at')
            ->get();

        // Seed the welcome message on first visit
        if ($messages->isEmpty()) {
            $welcome = $user->chatMessages()->create([
                'role' => 'assistant',
                'content' => $this->onboardingAgent->welcomeMessage(),
                'context' => 'onboarding',
            ]);
            $messages = collect([$welcome]);
        }

        return Inertia::render('Onboarding/Chat', [
            'messages' => ChatMessageResource::collection($messages)->resolve(),
            'onboardingComplete' => $this->onboardingAgent->isOnboardingComplete($user),
            'profileReady' => $this->onboardingAgent->isOnboardingComplete($user),
        ]);
    }

    /**
     * Handle a user chat message during onboarding.
     *
     * Saves the user message, gets the AI response, saves it, and
     * returns the full updated state as JSON (for fetch-based frontend).
     */
    public function store(ChatRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        // Save user message
        $userMsg = $user->chatMessages()->create([
            'role' => 'user',
            'content' => $validated['message'],
            'context' => 'onboarding',
        ]);

        // Get AI response
        $responseText = $this->onboardingAgent->chat($user, $validated['message']);

        // Save assistant message
        $assistantMsg = $user->chatMessages()->create([
            'role' => 'assistant',
            'content' => $responseText,
            'context' => 'onboarding',
        ]);

        $isComplete = $this->onboardingAgent->isOnboardingComplete($user);

        return response()->json([
            'userMessage' => new ChatMessageResource($userMsg),
            'assistantMessage' => new ChatMessageResource($assistantMsg),
            'onboardingComplete' => $isComplete,
        ]);
    }

    /**
     * Generate and confirm the user's interest profile from the chat.
     *
     * Called when the user confirms the profile summary.
     */
    public function confirmProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $profile = $this->profileGenerator->generateFromChat($user);

        if (empty($profile)) {
            return response()->json([
                'success' => false,
                'message' => 'Nu s-a putut genera profilul. Te rugăm să continui conversația.',
            ], 422);
        }

        // Merge with any existing profile data
        $existingProfile = $user->interest_profile ?? [];
        $merged = $this->profileGenerator->mergeProfiles($existingProfile, $profile);

        // Extract non-score metadata
        $city = $merged['city'] ?? $user->city;
        unset($merged['city'], $merged['price_sensitive'], $merged['preferred_times']);

        $user->update([
            'interest_profile' => $merged,
            'city' => is_string($city) ? $city : $user->city,
            'onboarding_completed' => true,
        ]);

        return response()->json([
            'success' => true,
            'profile' => $merged,
            'redirectTo' => route('dashboard'),
        ]);
    }

    /**
     * Return chat history (JSON) for the given context (default onboarding).
     */
    public function apiHistory(Request $request): JsonResponse
    {
        $context = $request->string('context')->toString() ?: 'onboarding';

        $messages = $request->user()->chatMessages()
            ->where('context', $context)
            ->orderBy('created_at')
            ->get();

        return ChatMessageResource::collection($messages)->response();
    }

    /**
     * Show the ongoing profile-update chat page.
     */
    public function profileChat(Request $request): Response
    {
        $user = $request->user();

        $messages = $user->chatMessages()
            ->where('context', 'profile_update')
            ->orderBy('created_at')
            ->get();

        if ($messages->isEmpty()) {
            $welcome = $user->chatMessages()->create([
                'role' => 'assistant',
                'content' => 'Salut! Spune-mi ce s-a schimbat — ce să adaug, să scot sau să ajustez în preferințele tale.',
                'context' => 'profile_update',
            ]);
            $messages = collect([$welcome]);
        }

        return Inertia::render('Dashboard/ProfileChat', [
            'messages' => ChatMessageResource::collection($messages)->resolve(),
        ]);
    }

    /**
     * Handle a profile-update chat message.
     */
    public function profileChatStore(ChatRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        $userMsg = $user->chatMessages()->create([
            'role' => 'user',
            'content' => $validated['message'],
            'context' => 'profile_update',
        ]);

        $responseText = $this->profileUpdateAgent->respond($user, $validated['message']);

        $assistantMsg = $user->chatMessages()->create([
            'role' => 'assistant',
            'content' => $responseText,
            'context' => 'profile_update',
        ]);

        return response()->json([
            'userMessage' => new ChatMessageResource($userMsg),
            'assistantMessage' => new ChatMessageResource($assistantMsg),
        ]);
    }

    /**
     * Apply the profile changes inferred from the profile-update conversation.
     */
    public function applyProfileUpdate(Request $request): JsonResponse
    {
        $user = $request->user();

        $changes = $this->profileGenerator->generateFromChat($user, 'profile_update');

        if ($changes === []) {
            return response()->json([
                'success' => false,
                'message' => 'Nu am putut detecta modificări. Continuă conversația.',
            ], 422);
        }

        $existingProfile = $user->interest_profile ?? [];
        $merged = $this->profileGenerator->mergeProfiles($existingProfile, $changes);

        $city = $merged['city'] ?? $user->city;
        unset($merged['city'], $merged['price_sensitive'], $merged['preferred_times']);

        $user->update([
            'interest_profile' => $merged,
            'city' => is_string($city) ? $city : $user->city,
        ]);

        return response()->json([
            'success' => true,
            'profile' => $merged,
            'redirectTo' => route('profile.show'),
        ]);
    }
}
