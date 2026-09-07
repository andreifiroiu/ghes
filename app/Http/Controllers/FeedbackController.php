<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Reaction;
use App\Http\Requests\DeleteFeedbackRequest;
use App\Http\Requests\FeedbackRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Feedback\ReactionRecorder;
use Illuminate\Http\JsonResponse;

class FeedbackController extends Controller
{
    public function __construct(
        private readonly ReactionRecorder $reactions,
    ) {}

    public function store(FeedbackRequest $request): JsonResponse
    {
        /** @var array{event_id: string, reaction: string} $validated */
        $validated = $request->validated();

        $reaction = Reaction::from($validated['reaction']);

        $this->reactions->record($request->user(), $validated['event_id'], $reaction);

        return response()->json([
            'message' => 'Feedback recorded.',
            'reaction' => $reaction->value,
        ]);
    }

    public function destroy(DeleteFeedbackRequest $request): JsonResponse
    {
        /** @var array{event_id: string} $validated */
        $validated = $request->validated();

        $this->reactions->remove($request->user(), $validated['event_id']);

        return response()->json([
            'message' => 'Feedback removed.',
        ]);
    }

    /**
     * API twin of store(). Idempotent — recording the same reaction twice
     * overwrites in place — so the client may retry it blindly.
     */
    public function apiStore(FeedbackRequest $request): JsonResponse
    {
        /** @var array{event_id: string, reaction: string} $validated */
        $validated = $request->validated();

        $reaction = Reaction::from($validated['reaction']);

        $this->reactions->record($request->user(), $validated['event_id'], $reaction);

        return ApiResponse::message('Feedback recorded.', ['reaction' => $reaction->value]);
    }

    /**
     * API twin of destroy().
     */
    public function apiDestroy(DeleteFeedbackRequest $request): JsonResponse
    {
        /** @var array{event_id: string} $validated */
        $validated = $request->validated();

        $this->reactions->remove($request->user(), $validated['event_id']);

        return ApiResponse::message('Feedback removed.');
    }
}
