<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Reaction;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EventController extends Controller
{
    public function index(Request $request): Response
    {
        $events = $this->browseQuery($request)->paginate(20)->withQueryString();

        return Inertia::render('Events/Index', [
            'events' => EventResource::collection($events),
            'filters' => $request->only(['search', 'category', 'city']),
        ]);
    }

    public function show(Request $request, Event $event): Response
    {
        $event->load(['reactions' => fn ($query) => $query->where('user_id', $request->user()->id)]);

        return Inertia::render('Events/Show', [
            'event' => new EventResource($event),
        ]);
    }

    /**
     * Build the filtered browse query for the events list, scoped to the current
     * user's reaction (for highlight state) and excluding events they dismissed.
     *
     * @return Builder<Event>
     */
    private function browseQuery(Request $request): Builder
    {
        $user = $request->user();

        $query = Event::upcoming()
            ->with(['reactions' => fn ($q) => $q->where('user_id', $user->id)])
            ->orderBy('starts_at', 'asc');

        if ($request->filled('search')) {
            $searchIds = Event::search($request->string('search')->toString())->keys();
            $query->whereIn('id', $searchIds);
        }

        if ($request->filled('category')) {
            $query->where('category', $request->string('category')->toString());
        }

        if ($request->filled('city')) {
            $query->where('city', $request->string('city')->toString());
        }

        $dismissedEventIds = $user->reactions()
            ->whereIn('reaction', [Reaction::NotInterested, Reaction::Hidden])
            ->pluck('event_id');

        $query->whereNotIn('id', $dismissedEventIds);

        return $query;
    }

    public function saved(Request $request): Response
    {
        return Inertia::render('Dashboard/SavedEvents', [
            'events' => EventResource::collection($this->savedEventsFor($request->user()))->resolve(),
        ]);
    }

    public function apiSaved(Request $request): JsonResponse
    {
        return EventResource::collection($this->savedEventsFor($request->user()))->response();
    }

    /**
     * Upcoming events the user has bookmarked, soonest first.
     *
     * @return Collection<int, Event>
     */
    private function savedEventsFor(User $user): Collection
    {
        $savedEventIds = $user->reactions()
            ->where('reaction', Reaction::Saved)
            ->pluck('event_id');

        return Event::whereIn('id', $savedEventIds)
            ->with(['reactions' => fn ($query) => $query->where('user_id', $user->id)])
            ->orderBy('starts_at')
            ->get();
    }

    public function apiIndex(Request $request): JsonResponse
    {
        $events = $this->browseQuery($request)->paginate(20)->withQueryString();

        return EventResource::collection($events)->response();
    }

    public function apiShow(Request $request, Event $event): JsonResponse
    {
        $event->load(['reactions' => fn ($query) => $query->where('user_id', $request->user()->id)]);

        return (new EventResource($event))->response();
    }
}
