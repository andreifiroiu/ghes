<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Reaction;
use App\Http\Requests\DeleteFeedbackRequest;
use App\Http\Requests\FeedbackRequest;
use App\Jobs\ProcessFeedbackJob;
use App\Models\UserEventReaction;
use Illuminate\Http\JsonResponse;

class FeedbackController extends Controller
{
    public function store(FeedbackRequest $request): JsonResponse
    {
        /** @var array{event_id: string, reaction: string} $validated */
        $validated = $request->validated();

        $user = $request->user();
        $reaction = Reaction::from($validated['reaction']);

        $userReaction = UserEventReaction::updateOrCreate(
            [
                'user_id' => $user->id,
                'event_id' => $validated['event_id'],
            ],
            [
                'reaction' => $reaction,
            ],
        );

        ProcessFeedbackJob::dispatch($userReaction->id);

        return response()->json([
            'message' => 'Feedback recorded.',
            'reaction' => $reaction->value,
        ]);
    }

    public function destroy(DeleteFeedbackRequest $request): JsonResponse
    {
        /** @var array{event_id: string} $validated */
        $validated = $request->validated();

        UserEventReaction::query()
            ->where('user_id', $request->user()->id)
            ->where('event_id', $validated['event_id'])
            ->delete();

        return response()->json([
            'message' => 'Feedback removed.',
        ]);
    }
}
