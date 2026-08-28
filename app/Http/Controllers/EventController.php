<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Reaction;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\User;
use Carbon\Carbon;
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
            'filters' => $request->only(['search', 'category', 'city', 'date']),
        ]);
    }

    public function show(Request $request, Event $event): Response
    {
        abort_if($event->is_hidden, 404);

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
            ->visible()
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

        if ($request->filled('date')) {
            $timezone = $this->cityTimezone();

            try {
                $day = Carbon::parse($request->string('date')->toString(), $timezone);

                $query->whereBetween('starts_at', [
                    $day->copy()->startOfDay()->utc(),
                    $day->copy()->endOfDay()->utc(),
                ]);
            } catch (\Throwable) {
                // Ignore an unparseable date and fall back to all upcoming events.
            }
        }

        $dismissedEventIds = $user->reactions()
            ->whereIn('reaction', [Reaction::NotInterested, Reaction::Hidden])
            ->pluck('event_id');

        $query->whereNotIn('id', $dismissedEventIds);

        return $query;
    }

    /**
     * Resolve the timezone used to interpret a user-selected calendar date,
     * defaulting to the configured default city's timezone.
     */
    private function cityTimezone(): string
    {
        $city = (string) config('eventpulse.default_city');

        return (string) config("eventpulse.cities.{$city}.timezone", config('app.timezone'));
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
            ->visible()
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
        abort_if($event->is_hidden, 404);

        $event->load(['reactions' => fn ($query) => $query->where('user_id', $request->user()->id)]);

        return (new EventResource($event))->response();
    }
}
