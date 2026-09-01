<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ChatRequest;
use App\Http\Resources\ChatMessageResource;
use App\Models\User;
use App\Services\Chat\OnboardingAgent;
use App\Services\Chat\ProfileGenerator;
use App\Services\Chat\ProfileUpdateAgent;
use App\Services\City\CityCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
        $city = $this->resolveCity($user, $merged['city'] ?? null);
        $cityNotice = $this->cityNotice($merged['city'] ?? null);
        unset($merged['city'], $merged['price_sensitive'], $merged['preferred_times']);

        // Emptiness is only knowable once the metadata is stripped: a reply
        // carrying nothing but a city passes the check above, then leaves an
        // empty score map behind a modal the user cannot dismiss.
        if ($merged === []) {
            return response()->json([
                'success' => false,
                'message' => 'Nu s-a putut genera profilul. Te rugăm să continui conversația.',
            ], 422);
        }

        $user->update([
            'interest_profile' => $merged,
            'city' => $city,
            'onboarding_completed' => true,
        ]);

        return response()->json([
            'success' => true,
            'profile' => $merged,
            'cityNotice' => $cityNotice,
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

        $city = $this->resolveCity($user, $merged['city'] ?? null);
        $cityNotice = $this->cityNotice($merged['city'] ?? null);
        unset($merged['city'], $merged['price_sensitive'], $merged['preferred_times']);

        $user->update([
            'interest_profile' => $merged,
            'city' => $city,
        ]);

        return response()->json([
            'success' => true,
            'profile' => $merged,
            'cityNotice' => $cityNotice,
            'redirectTo' => route('profile.show'),
        ]);
    }

    /**
     * Settle the city to store after a profile generation.
     *
     * The LLM is asked for a city but the conversation is never steered to it,
     * so the answer is usually null and occasionally a city Ghes does not
     * cover. Writing that unchecked would silently empty the user's feed, so
     * anything outside the configured catalogue is discarded in favour of what
     * the user already has, then the covered city.
     */
    private function resolveCity(User $user, mixed $llmCity): string
    {
        $requested = is_string($llmCity) ? $llmCity : null;
        $resolved = CityCatalog::resolveLabel($requested);

        if ($resolved !== null) {
            return $resolved;
        }

        $kept = $user->city ?? CityCatalog::defaultLabel();

        // Only worth a line when the user actually named somewhere. A null
        // city is the ordinary case — the onboarding script never asks.
        if (filled($requested)) {
            Log::info('Discarded an uncovered city from the profile chat.', [
                'user_id' => $user->id,
                'requested' => $requested,
                'kept' => $kept,
            ]);
        }

        return $kept;
    }

    /**
     * The message to show when the chat named a city Ghes does not cover.
     *
     * Without it the profile-update chat answers "applied" to a move the
     * server just reverted, which is the one thing that page must not do.
     */
    private function cityNotice(mixed $llmCity): ?string
    {
        $requested = is_string($llmCity) ? $llmCity : null;

        if (blank($requested) || CityCatalog::resolveLabel($requested) !== null) {
            return null;
        }

        return sprintf(
            'Deocamdată acoperim doar %s, așa că am păstrat orașul tău actual.',
            implode(', ', CityCatalog::labels())
        );
    }
}
