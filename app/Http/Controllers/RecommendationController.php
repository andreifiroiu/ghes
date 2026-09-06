<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivitySurface;
use App\Enums\ActivityType;
use App\Enums\Reaction;
use App\Http\Controllers\Concerns\ResolvesCity;
use App\Http\Resources\EventResource;
use App\Http\Resources\RecommendationBatchResource;
use App\Http\Responses\ApiResponse;
use App\Models\Event;
use App\Models\Notification;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
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
        private readonly ActivityLogger $activity,
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

        $weekendEvents = $this->weekendEvents($user);

        // Every list in one call: an impression is an impression regardless of
        // which rail it came from, and the discovery flag already lives in
        // discovery_logs for anyone who needs to tell them apart. The weekend
        // rail counts too — it is on screen, so leaving it out would undercount
        // exactly the events the page pushes hardest.
        $this->activity->logMany(
            ActivityType::EventImpression,
            ActivitySurface::Dashboard,
            [
                ...$recommendations->pluck('id'),
                ...$discoveryEvents->pluck('id'),
                ...$weekendEvents->pluck('id'),
            ],
            $user,
        );

        return Inertia::render('Dashboard/Index', [
            'recommendations' => EventResource::collection($recommendations)->resolve(),
            'discoveryEvents' => EventResource::collection($discoveryEvents)->resolve(),
            'weekendEvents' => EventResource::collection($weekendEvents)->resolve(),
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

        // Same ranking fix as index(): whereIn() discards the engine's order.
        $recommendations = $this->inBatchOrder(
            Event::whereIn('id', $batch->recommendedEventIds)->withUserContext($user)->get(),
            $batch->recommendedEventIds,
        );
        $discoveryEvents = $this->inBatchOrder(
            Event::whereIn('id', $batch->discoveryEventIds)->withUserContext($user)->get(),
            $batch->discoveryEventIds,
        );

        $this->activity->logMany(
            ActivityType::EventImpression,
            ActivitySurface::forApi($request, ActivitySurface::MobileFeed),
            [...$recommendations->pluck('id'), ...$discoveryEvents->pluck('id')],
            $user,
        );

        return ApiResponse::item([
            'recommendations' => EventResource::collection($recommendations)->resolve(),
            'discovery' => EventResource::collection($discoveryEvents)->resolve(),
            'total_score' => $batch->totalScore,
        ]);
    }

    /**
     * Past recommendation batches (from sent notifications), newest first.
     */
    public function apiHistory(Request $request): JsonResponse
    {
        $user = $request->user();

        $notifications = Notification::query()
            ->where('user_id', $user->id)
            ->whereNotNull('sent_at')
            ->latest('sent_at')
            ->paginate((int) config('eventpulse.pagination.notifications', 20))
            ->withQueryString();

        return ApiResponse::paginated(RecommendationBatchResource::collection($notifications));
    }
}
