<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ApiErrorCode;
use App\Http\Requests\ChatRequest;
use App\Http\Resources\ChatMessageResource;
use App\Http\Responses\ApiResponse;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Chat\ChatThread;
use App\Services\Chat\OnboardingAgent;
use App\Services\Chat\ProfileGenerator;
use App\Services\Chat\ProfileUpdateAgent;
use App\Services\City\CityCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
    public function __construct(
        private readonly OnboardingAgent $onboardingAgent,
        private readonly ProfileGenerator $profileGenerator,
        private readonly ProfileUpdateAgent $profileUpdateAgent,
        private readonly ChatThread $thread,
    ) {}

    /**
     * Show the onboarding chat page.
     *
     * On first visit (no messages yet), creates the welcome message.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $messages = $this->thread->messagesFor($user, ChatThread::CONTEXT_ONBOARDING);

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
        $user = $request->user();

        [$userMsg, $assistantMsg] = $this->onboardingExchange($user, $request->validated()['message']);

        return response()->json([
            'userMessage' => new ChatMessageResource($userMsg),
            'assistantMessage' => new ChatMessageResource($assistantMsg),
            'onboardingComplete' => $this->onboardingAgent->isOnboardingComplete($user),
        ]);
    }

    /**
     * Save the user's onboarding message and the agent's reply.
     *
     * @return array{0: ChatMessage, 1: ChatMessage}
     */
    private function onboardingExchange(User $user, string $message): array
    {
        $userMsg = $user->chatMessages()->create([
            'role' => 'user',
            'content' => $message,
            'context' => ChatThread::CONTEXT_ONBOARDING,
        ]);

        $responseText = $this->onboardingAgent->chat($user, $message);

        $assistantMsg = $user->chatMessages()->create([
            'role' => 'assistant',
            'content' => $responseText,
            'context' => ChatThread::CONTEXT_ONBOARDING,
        ]);

        return [$userMsg, $assistantMsg];
    }

    /**
     * Generate and confirm the user's interest profile from the chat.
     *
     * Called when the user confirms the profile summary.
     */
    public function confirmProfile(Request $request): JsonResponse
    {
        $result = $this->confirmOnboardingProfile($request->user());

        if ($result === null) {
            return response()->json([
                'success' => false,
                'message' => self::ONBOARDING_INCOMPLETE,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'profile' => $result['profile'],
            'cityNotice' => $result['city_notice'],
            'redirectTo' => route('dashboard'),
        ]);
    }

    private const ONBOARDING_INCOMPLETE = 'Nu s-a putut genera profilul. Te rugăm să continui conversația.';

    private const NO_CHANGES_DETECTED = 'Nu am putut detecta modificări. Continuă conversația.';

    /**
     * Generate, merge and store the profile from the onboarding chat.
     *
     * Null when the conversation does not yet yield a profile. Shared by the
     * web page and the API so the two cannot drift on what "confirmed" means.
     *
     * @return array{profile: array<string, mixed>, city_notice: string|null}|null
     */
    private function confirmOnboardingProfile(User $user): ?array
    {
        $profile = $this->profileGenerator->generateFromChat($user);

        if (empty($profile)) {
            return null;
        }

        // Merge with any existing profile data
        $existingProfile = $user->interest_profile ?? [];
        $merged = $this->profileGenerator->mergeProfiles($existingProfile, $profile);

        // Extract non-score metadata
        $city = $this->resolveCity($user, $merged['city'] ?? null);
        $cityNotice = $this->cityNotice($merged['city'] ?? null);
        $summary = $this->resolveSummary($user, $merged['summary'] ?? null, ChatThread::CONTEXT_ONBOARDING);
        $this->warnOnMissingSummary($user, $summary, $merged['summary'] ?? null);
        unset($merged['city'], $merged['price_sensitive'], $merged['preferred_times'], $merged['summary']);

        // Emptiness is only knowable once the metadata is stripped: a reply
        // carrying nothing but a city passes the check above, then leaves an
        // empty score map behind a modal the user cannot dismiss.
        if ($merged === []) {
            return null;
        }

        $user->update([
            'interest_profile' => $merged,
            'city' => $city,
            'onboarding_completed' => true,
            ...$this->summaryAttributes($user, $summary),
        ]);

        return ['profile' => $merged, 'city_notice' => $cityNotice];
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

        return ApiResponse::collection(ChatMessageResource::collection($messages));
    }

    /**
     * Show the ongoing profile-update chat page.
     */
    public function profileChat(Request $request): Response
    {
        $messages = $this->thread->messagesFor($request->user(), ChatThread::CONTEXT_PROFILE_UPDATE);

        return Inertia::render('Dashboard/ProfileChat', [
            'messages' => ChatMessageResource::collection($messages)->resolve(),
        ]);
    }

    /**
     * Handle a profile-update chat message.
     */
    public function profileChatStore(ChatRequest $request): JsonResponse
    {
        [$userMsg, $assistantMsg] = $this->profileExchange($request->user(), $request->validated()['message']);

        return response()->json([
            'userMessage' => new ChatMessageResource($userMsg),
            'assistantMessage' => new ChatMessageResource($assistantMsg),
        ]);
    }

    /**
     * Save the user's profile-update message and the agent's reply.
     *
     * @return array{0: ChatMessage, 1: ChatMessage}
     */
    private function profileExchange(User $user, string $message): array
    {
        $userMsg = $user->chatMessages()->create([
            'role' => 'user',
            'content' => $message,
            'context' => ChatThread::CONTEXT_PROFILE_UPDATE,
        ]);

        $responseText = $this->profileUpdateAgent->respond($user, $message);

        $assistantMsg = $user->chatMessages()->create([
            'role' => 'assistant',
            'content' => $responseText,
            'context' => ChatThread::CONTEXT_PROFILE_UPDATE,
        ]);

        return [$userMsg, $assistantMsg];
    }

    /**
     * Apply the profile changes inferred from the profile-update conversation.
     */
    public function applyProfileUpdate(Request $request): JsonResponse
    {
        $result = $this->applyProfileChanges($request->user());

        if ($result === null) {
            return response()->json([
                'success' => false,
                'message' => self::NO_CHANGES_DETECTED,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'profile' => $result['profile'],
            'cityNotice' => $result['city_notice'],
            'redirectTo' => route('profile.show'),
        ]);
    }

    /**
     * Apply the changes inferred from the profile-update chat, or null when
     * the conversation yields none. Shared by the web page and the API.
     *
     * @return array{profile: array<string, mixed>, city_notice: string|null}|null
     */
    private function applyProfileChanges(User $user): ?array
    {
        $changes = $this->profileGenerator->generateFromChat($user, ChatThread::CONTEXT_PROFILE_UPDATE);

        if ($changes === []) {
            return null;
        }

        $existingProfile = $user->interest_profile ?? [];
        $merged = $this->profileGenerator->mergeProfiles($existingProfile, $changes);

        $city = $this->resolveCity($user, $merged['city'] ?? null);
        $cityNotice = $this->cityNotice($merged['city'] ?? null);
        $summary = $this->resolveSummary($user, $merged['summary'] ?? null, ChatThread::CONTEXT_PROFILE_UPDATE);
        unset($merged['city'], $merged['price_sensitive'], $merged['preferred_times'], $merged['summary']);

        $user->update([
            'interest_profile' => $merged,
            'city' => $city,
            ...$this->summaryAttributes($user, $summary),
        ]);

        return ['profile' => $merged, 'city_notice' => $cityNotice];
    }

    // ---- API twins. Same work as the web methods above, one envelope.

    /**
     * The onboarding thread, seeded on first open like the web page.
     */
    public function apiOnboarding(Request $request): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::item([
            'messages' => ChatMessageResource::collection(
                $this->thread->messagesFor($user, ChatThread::CONTEXT_ONBOARDING),
            )->resolve(),
            'onboarding_complete' => $this->onboardingAgent->isOnboardingComplete($user),
        ]);
    }

    public function apiStore(ChatRequest $request): JsonResponse
    {
        $user = $request->user();

        [$userMsg, $assistantMsg] = $this->onboardingExchange($user, $request->validated()['message']);

        return ApiResponse::item([
            'user_message' => (new ChatMessageResource($userMsg))->resolve(),
            'assistant_message' => (new ChatMessageResource($assistantMsg))->resolve(),
            'onboarding_complete' => $this->onboardingAgent->isOnboardingComplete($user),
        ]);
    }

    /**
     * Confirm the profile. No redirect target: the native router decides.
     */
    public function apiConfirmProfile(Request $request): JsonResponse
    {
        $result = $this->confirmOnboardingProfile($request->user());

        if ($result === null) {
            return ApiResponse::error(
                ApiErrorCode::ValidationFailed,
                self::ONBOARDING_INCOMPLETE,
                422,
                ['conversation' => [self::ONBOARDING_INCOMPLETE]],
            );
        }

        return ApiResponse::item([
            'profile' => $result['profile'],
            'city_notice' => $result['city_notice'],
        ]);
    }

    public function apiProfileChat(Request $request): JsonResponse
    {
        return ApiResponse::collection(ChatMessageResource::collection(
            $this->thread->messagesFor($request->user(), ChatThread::CONTEXT_PROFILE_UPDATE),
        ));
    }

    public function apiProfileChatStore(ChatRequest $request): JsonResponse
    {
        [$userMsg, $assistantMsg] = $this->profileExchange($request->user(), $request->validated()['message']);

        return ApiResponse::item([
            'user_message' => (new ChatMessageResource($userMsg))->resolve(),
            'assistant_message' => (new ChatMessageResource($assistantMsg))->resolve(),
        ]);
    }

    public function apiApplyProfileUpdate(Request $request): JsonResponse
    {
        $result = $this->applyProfileChanges($request->user());

        if ($result === null) {
            return ApiResponse::error(
                ApiErrorCode::ValidationFailed,
                self::NO_CHANGES_DETECTED,
                422,
                ['conversation' => [self::NO_CHANGES_DETECTED]],
            );
        }

        return ApiResponse::item([
            'profile' => $result['profile'],
            'city_notice' => $result['city_notice'],
        ]);
    }

    /**
     * Settle the profile summary to store after a profile generation.
     *
     * The generator is asked for one, but the model does occasionally return
     * scores and nothing else — so the recap the user was shown at the end of
     * the chat is the fallback. Both can be absent (a profile-update chat has
     * no [PROFILE_READY] marker), in which case the existing summary stands
     * rather than being cleared by a refinement.
     */
    private function resolveSummary(User $user, mixed $generated, string $context): ?string
    {
        if (is_string($generated) && trim($generated) !== '') {
            return trim($generated);
        }

        return $this->profileGenerator->summaryFromChat($user, $context);
    }

    /**
     * Re-reading the same [PROFILE_READY] message must not look like a fresh
     * summary: the page shows "Actualizat <date>", and a date that moves while
     * the prose does not is worse than no date at all.
     *
     * @return array{profile_summary?: string, profile_summary_updated_at?: Carbon}
     */
    private function summaryAttributes(User $user, ?string $summary): array
    {
        if ($summary === null || $summary === $user->profile_summary) {
            return [];
        }

        return [
            'profile_summary' => $summary,
            'profile_summary_updated_at' => Carbon::now(),
        ];
    }

    /**
     * Onboarding that ends with no summary from either source is a defect, not
     * a data condition — the model dropped the key *and* the [PROFILE_READY]
     * recap the fallback reads was missing or renamed. Left unlogged it shows
     * up only as a profile page telling the user to go and chat, which is what
     * they just did.
     */
    private function warnOnMissingSummary(User $user, ?string $resolved, mixed $generated): void
    {
        if ($resolved !== null) {
            return;
        }

        Log::warning('Profile confirmed with no summary from either source', [
            'user_id' => $user->id,
            'generated_type' => get_debug_type($generated),
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
