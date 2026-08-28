<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\EventResource;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EventController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Event::canonical()->upcoming()->orderBy('starts_at', 'asc');

        if ($request->filled('search')) {
            $searchIds = Event::search($request->string('search')->toString())
                ->keys();
            $query->whereIn('id', $searchIds);
        }

        if ($request->filled('category')) {
            $query->where('category', $request->string('category')->toString());
        }

        if ($request->filled('city')) {
            $query->where('city', $request->string('city')->toString());
        }

        $events = $query->paginate(20)->withQueryString();

        return Inertia::render('Events/Index', [
            'events' => EventResource::collection($events),
            'filters' => $request->only(['search', 'category', 'city']),
        ]);
    }

    public function show(Event $event): Response
    {
        $event = $this->resolveCanonical($event);

        return Inertia::render('Events/Show', [
            'event' => new EventResource($event),
        ]);
    }

    public function apiIndex(Request $request): JsonResponse
    {
        $query = Event::canonical()->upcoming()->orderBy('starts_at', 'asc');

        if ($request->filled('search')) {
            $searchIds = Event::search($request->string('search')->toString())
                ->keys();
            $query->whereIn('id', $searchIds);
        }

        if ($request->filled('category')) {
            $query->where('category', $request->string('category')->toString());
        }

        if ($request->filled('city')) {
            $query->where('city', $request->string('city')->toString());
        }

        $events = $query->paginate(20)->withQueryString();

        return EventResource::collection($events)->response();
    }

    public function apiShow(Event $event): JsonResponse
    {
        return (new EventResource($this->resolveCanonical($event)))->response();
    }

    /**
     * Follow a merged duplicate to the event it now lives under.
     *
     * Links in already-sent digests point at ids that may since have been
     * merged away; they must still resolve to the surviving event rather than
     * showing a stale duplicate.
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
}
