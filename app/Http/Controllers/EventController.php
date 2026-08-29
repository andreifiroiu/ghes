<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Reaction;
use App\Http\Controllers\Concerns\ResolvesCity;
use App\Http\Resources\EventResource;
use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EventController extends Controller
{
    use ResolvesCity;

    public function index(Request $request): Response
    {
        $events = $this->browseQuery($request)->paginate((int) config('eventpulse.pagination.events', 20))->withQueryString();

        return Inertia::render('Events/Index', [
            'events' => EventResource::collection($events),
            'filters' => $request->only(['search', 'category', 'city', 'date', 'range']),
        ]);
    }

    public function show(Request $request, Event $event): Response
    {
        $event = $this->resolveCanonical($event);

        abort_if($event->is_hidden, 404);

        if (($user = $request->user()) !== null) {
            $event->load([
                'reactions' => fn ($query) => $query->where('user_id', $user->id),
                'bookmarks' => fn ($query) => $query->where('user_id', $user->id),
            ]);
        }

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
            ->canonical()
            ->orderBy('starts_at', 'asc');

        // Guests browse the same list read-only. `withUserContext()` takes a
        // non-nullable User, and a guest has no reaction or bookmark state to
        // load anyway.
        if ($user !== null) {
            $query->withUserContext($user);
        }

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

        if ($request->string('range')->toString() === 'weekend') {
            [$start, $end] = $this->weekendRange();

            $query->whereBetween('starts_at', [$start, $end]);
        }

        if ($user !== null) {
            $dismissedEventIds = $user->reactions()
                ->where('reaction', Reaction::NotInterested)
                ->pluck('event_id');

            $query->whereNotIn('id', $dismissedEventIds);
        }

        return $query;
    }

    /**
     * Follow a merged duplicate to the event it now lives under.
     *
     * Links in already-sent digests point at ids that may since have been
     * merged away; they must still resolve to the surviving event rather than
     * showing a stale duplicate. Moderation is applied to the survivor, not to
     * the id that was clicked.
     */
    private function resolveCanonical(Event $event): Event
    {
        $seen = 0;

        while ($event->merged_into_id !== null && $seen < 5) {
            $canonical = $event->canonicalEvent;

            if ($canonical === null) {
                break;
            }

            $event = $canonical;
            $seen++;
        }

        return $event;
    }

    public function apiIndex(Request $request): JsonResponse
    {
        $events = $this->browseQuery($request)->paginate((int) config('eventpulse.pagination.events', 20))->withQueryString();

        return EventResource::collection($events)->response();
    }

    public function apiShow(Request $request, Event $event): JsonResponse
    {
        $event = $this->resolveCanonical($event);

        abort_if($event->is_hidden, 404);

        if (($user = $request->user()) !== null) {
            $event->load([
                'reactions' => fn ($query) => $query->where('user_id', $user->id),
                'bookmarks' => fn ($query) => $query->where('user_id', $user->id),
            ]);
        }

        return (new EventResource($event))->response();
    }
}
