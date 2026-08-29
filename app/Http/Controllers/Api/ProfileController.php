<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProfileUpdateRequest;
use App\Http\Resources\UserResource;
use App\Models\DiscoveryLog;
use App\Models\UserEventReaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return (new UserResource($request->user()))->response();
    }

    public function update(ProfileUpdateRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update($request->validated());

        return (new UserResource($user->fresh()))->response();
    }

    /**
     * Feedback stats and discovery hit-rate for the authenticated user.
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();

        $reactionCounts = $user->reactions()
            ->get(['reaction'])
            ->countBy(fn (UserEventReaction $reaction) => $reaction->reaction->value);

        $resolvedDiscovery = DiscoveryLog::query()
            ->where('user_id', $user->id)
            ->whereNotNull('outcome')
            ->get(['outcome']);

        $resolvedCount = $resolvedDiscovery->count();
        $discoveryHits = $resolvedDiscovery->whereIn('outcome', DiscoveryLog::POSITIVE_OUTCOMES)->count();

        return response()->json([
            'reactions' => [
                'total' => $user->reactions()->count(),
                'by_type' => $reactionCounts,
                // Bookmarks are their own signal now; the key is kept for API
                // compatibility but sourced from event_bookmarks.
                'saved' => $user->bookmarks()->count(),
            ],
            'discovery' => [
                'openness' => (float) $user->discovery_openness,
                'surfaced' => DiscoveryLog::where('user_id', $user->id)->count(),
                'resolved' => $resolvedCount,
                'hits' => $discoveryHits,
                'hit_rate' => $resolvedCount > 0 ? round($discoveryHits / $resolvedCount, 4) : 0.0,
            ],
        ]);
    }
}
