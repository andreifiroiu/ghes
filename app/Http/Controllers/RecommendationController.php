<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\Notification;
use App\Services\Recommendation\RecommendationEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RecommendationController extends Controller
{
    public function __construct(
        private readonly RecommendationEngine $recommendationEngine,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $batch = $this->recommendationEngine->recommend($user);

        $withReaction = ['reactions' => fn ($query) => $query->where('user_id', $user->id)];

        $recommendations = Event::whereIn('id', $batch->recommendedEventIds)->with($withReaction)->get();
        $discoveryEvents = Event::whereIn('id', $batch->discoveryEventIds)->with($withReaction)->get();

        return Inertia::render('Dashboard/Index', [
            'recommendations' => EventResource::collection($recommendations)->resolve(),
            'discoveryEvents' => EventResource::collection($discoveryEvents)->resolve(),
        ]);
    }

    public function apiIndex(Request $request): JsonResponse
    {
        $user = $request->user();

        $batch = $this->recommendationEngine->recommend($user);

        $recommendations = Event::whereIn('id', $batch->recommendedEventIds)->get();

        return response()->json([
            'recommendations' => EventResource::collection($recommendations),
            'discovery' => EventResource::collection(
                Event::whereIn('id', $batch->discoveryEventIds)->get(),
            ),
            'total_score' => $batch->totalScore,
        ]);
    }

    /**
     * Past recommendation batches (from sent notifications), newest first.
     */
    public function apiHistory(Request $request): JsonResponse
    {
        $notifications = Notification::query()
            ->where('user_id', $request->user()->id)
            ->whereNotNull('sent_at')
            ->latest('sent_at')
            ->limit(50)
            ->get();

        $history = $notifications->map(function (Notification $notification) {
            $eventIds = array_merge(
                $notification->event_ids ?? [],
                $notification->discovery_event_ids ?? [],
            );

            return [
                'notification_id' => $notification->id,
                'sent_at' => $notification->sent_at,
                'discovery_event_ids' => $notification->discovery_event_ids,
                'events' => EventResource::collection(Event::whereIn('id', $eventIds)->get()),
            ];
        });

        return response()->json(['history' => $history]);
    }
}
