<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivitySurface;
use App\Enums\ActivityType;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\Notification;
use App\Services\Activity\ActivityLogger;
use App\Services\Recommendation\RecommendationEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RecommendationController extends Controller
{
    public function __construct(
        private readonly RecommendationEngine $recommendationEngine,
        private readonly ActivityLogger $activity,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $batch = $this->recommendationEngine->recommend($user);

        $recommendations = Event::whereIn('id', $batch->recommendedEventIds)
            ->withUserContext($user)->get();
        $discoveryEvents = Event::whereIn('id', $batch->discoveryEventIds)
            ->withUserContext($user)->get();

        // Both lists in one call: an impression is an impression regardless of
        // which rail it came from, and the discovery flag already lives in
        // discovery_logs for anyone who needs to tell them apart.
        $this->activity->logMany(
            ActivityType::EventImpression,
            ActivitySurface::Dashboard,
            [...$recommendations->pluck('id'), ...$discoveryEvents->pluck('id')],
            $user,
        );

        return Inertia::render('Dashboard/Index', [
            'recommendations' => EventResource::collection($recommendations)->resolve(),
            'discoveryEvents' => EventResource::collection($discoveryEvents)->resolve(),
        ]);
    }

    public function apiIndex(Request $request): JsonResponse
    {
        $user = $request->user();

        $batch = $this->recommendationEngine->recommend($user);

        $recommendations = Event::whereIn('id', $batch->recommendedEventIds)
            ->withUserContext($user)->get();
        $discoveryEvents = Event::whereIn('id', $batch->discoveryEventIds)
            ->withUserContext($user)->get();

        $this->activity->logMany(
            ActivityType::EventImpression,
            ActivitySurface::Api,
            [...$recommendations->pluck('id'), ...$discoveryEvents->pluck('id')],
            $user,
        );

        return response()->json([
            'recommendations' => EventResource::collection($recommendations),
            'discovery' => EventResource::collection($discoveryEvents),
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

        $user = $request->user();

        $history = $notifications->map(function (Notification $notification) use ($user) {
            $eventIds = array_merge(
                $notification->event_ids ?? [],
                $notification->discovery_event_ids ?? [],
            );

            return [
                'notification_id' => $notification->id,
                'sent_at' => $notification->sent_at,
                'discovery_event_ids' => $notification->discovery_event_ids,
                'events' => EventResource::collection(
                    Event::whereIn('id', $eventIds)->withUserContext($user)->get(),
                ),
            ];
        });

        return response()->json(['history' => $history]);
    }
}
