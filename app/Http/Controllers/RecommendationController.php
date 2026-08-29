<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Reaction;
use App\Http\Controllers\Concerns\ResolvesCity;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\Notification;
use App\Models\User;
use App\Services\Processing\EventTextNormalizer;
use App\Services\Recommendation\DashboardStatsBuilder;
use App\Services\Recommendation\RecommendationEngine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RecommendationController extends Controller
{
    use ResolvesCity;

    public function __construct(
        private readonly RecommendationEngine $recommendationEngine,
        private readonly DashboardStatsBuilder $dashboardStats,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $batch = $this->recommendationEngine->recommend($user);

        // whereIn() returns rows in the database's order, which discards the
        // engine's ranking entirely — the section is titled "recommended for
        // you" but was being served unsorted. Restore the batch order in PHP
        // rather than a raw CASE expression, to stay portable across the
        // sqlite/Postgres split.
        $recommendations = $this->inBatchOrder(
            Event::whereIn('id', $batch->recommendedEventIds)->withUserContext($user)->get(),
            $batch->recommendedEventIds,
        );
        $discoveryEvents = $this->inBatchOrder(
            Event::whereIn('id', $batch->discoveryEventIds)->withUserContext($user)->get(),
            $batch->discoveryEventIds,
        );

        $stats = $this->dashboardStats->build($user);

        // "Has a city" must mean the same thing here as it does to the query:
        // a city that survives slugging. The onboarding chat writes users.city
        // as free LLM text with no validation, so values like "!!!" or a lone
        // space are non-empty strings that slug to null — the filter is then
        // skipped entirely and the user silently gets every city's events under
        // a header naming their junk city. Deriving both from the slug keeps
        // the page honest and lets the "set your city" prompt actually fire.
        $citySlug = EventTextNormalizer::citySlug($user->city);
        $hasUsableCity = $citySlug !== null;

        return Inertia::render('Dashboard/Index', [
            'recommendations' => EventResource::collection($recommendations)->resolve(),
            'discoveryEvents' => EventResource::collection($discoveryEvents)->resolve(),
            'weekendEvents' => EventResource::collection(
                $this->weekendEvents($user),
            )->resolve(),
            'stats' => $stats,
            'city' => $hasUsableCity ? $user->city : $this->cityLabel(),
            // Drives the empty states: they need to distinguish "no profile
            // yet" from "no city set" from "genuinely nothing on in town".
            'onboardingCompleted' => (bool) $user->onboarding_completed,
            'hasCity' => $hasUsableCity,
            'hasEventsInCity' => $stats['upcoming'] > 0,
        ]);
    }

    /**
     * Reorder fetched events to match the id order the engine ranked them in.
     *
     * @param  Collection<int, Event>  $events
     * @param  list<string>  $orderedIds
     * @return Collection<int, Event>
     */
    private function inBatchOrder(Collection $events, array $orderedIds): Collection
    {
        $position = array_flip($orderedIds);

        return $events
            ->sortBy(fn (Event $event) => $position[$event->id] ?? PHP_INT_MAX)
            ->values();
    }

    /**
     * Events during the upcoming weekend in the user's city — the same gates
     * as the recommendation candidates, ordered by start time rather than
     * scored, so the section reads as a schedule.
     *
     * @return Collection<int, Event>
     */
    private function weekendEvents(User $user, int $limit = 6): Collection
    {
        [$start, $end] = $this->weekendRange();

        $citySlug = EventTextNormalizer::citySlug($user->city);

        // Honour "nu mă interesează" the way every sibling list does. Without
        // this a dismissed event disappears from the recommendations and from
        // /events?range=weekend — which is where this section's own "Vezi tot"
        // link points — but stays pinned in this rail forever, with no control
        // on the card that can remove it again.
        $dismissedEventIds = $user->reactions()
            ->where('reaction', Reaction::NotInterested)
            ->pluck('event_id');

        return Event::upcoming()
            ->visible()
            ->canonical()
            ->where('is_classified', true)
            ->when($citySlug !== null, fn ($query) => $query->where('city_slug', $citySlug))
            ->whereNotIn('id', $dismissedEventIds)
            ->whereBetween('starts_at', [$start, $end])
            ->orderBy('starts_at')
            ->limit($limit)
            ->withUserContext($user)
            ->get();
    }

    public function apiIndex(Request $request): JsonResponse
    {
        $user = $request->user();

        $batch = $this->recommendationEngine->recommend($user);

        $recommendations = Event::whereIn('id', $batch->recommendedEventIds)
            ->withUserContext($user)->get();

        return response()->json([
            'recommendations' => EventResource::collection($recommendations),
            'discovery' => EventResource::collection(
                Event::whereIn('id', $batch->discoveryEventIds)->withUserContext($user)->get(),
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
