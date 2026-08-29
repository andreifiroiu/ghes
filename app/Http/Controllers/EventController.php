<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivitySurface;
use App\Enums\ActivityType;
use App\Enums\Reaction;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Services\Activity\ActivityLogger;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EventController extends Controller
{
    /**
     * Filter keys recorded with a search, and echoed back to the page.
     *
     * @var list<string>
     */
    private const FILTER_KEYS = ['search', 'category', 'city', 'date', 'range'];

    /**
     * Filters the last browseQuery() call actually applied, keyed as above.
     *
     * @var array<string, string>
     */
    private array $appliedFilters = [];

    public function __construct(
        private readonly ActivityLogger $activity,
    ) {}

    public function index(Request $request): Response
    {
        $events = $this->browseQuery($request)->paginate((int) config('eventpulse.pagination.events', 20))->withQueryString();

        $this->recordBrowse($request, $events->pluck('id')->all(), ActivitySurface::EventsIndex);

        return Inertia::render('Events/Index', [
            'events' => EventResource::collection($events),
            'filters' => $request->only(self::FILTER_KEYS),
        ]);
    }

    public function show(Request $request, Event $event): Response
    {
        $event = $event->resolveCanonical();

        abort_if($event->is_hidden, 404);

        $this->activity->log(
            ActivityType::EventView,
            ActivitySurface::EventDetail,
            eventId: $event->id,
            user: $request->user(),
        );

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
     * Record what a browse actually put in front of someone.
     *
     * Impressions are logged server-side from the ids the page really rendered,
     * rather than from an IntersectionObserver in the client. That undercounts
     * nothing to ad-blockers, survives a closed tab, and — the reason it
     * matters — gives click-through rate a denominator that is exactly the set
     * of events we showed.
     *
     * A search row is written only when a filter is set, so an unfiltered browse
     * does not pollute the "what are people looking for" report with blanks.
     *
     * The filters recorded are the ones browseQuery() actually applied, not the
     * ones the request asked for. An unparseable `?date=` and any `?range=`
     * other than `weekend` are silently dropped from the query, and recording
     * them would have the analytics reason about a filter that never ran —
     * attributing an unfiltered result count to it.
     *
     * @param  list<string>  $eventIds
     */
    private function recordBrowse(Request $request, array $eventIds, ActivitySurface $surface): void
    {
        $user = $request->user();
        $filters = $this->appliedFilters;

        if ($filters !== []) {
            $this->activity->log(
                ActivityType::Search,
                $surface,
                user: $user,
                context: ['filters' => $filters, 'results' => count($eventIds)],
            );
        }

        $this->activity->logMany(ActivityType::EventImpression, $surface, $eventIds, $user);
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
        $this->appliedFilters = [];

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
            $term = $request->string('search')->toString();
            $searchIds = Event::search($term)->keys();
            $query->whereIn('id', $searchIds);
            $this->appliedFilters['search'] = $term;
        }

        if ($request->filled('category')) {
            $this->appliedFilters['category'] = $request->string('category')->toString();
            $query->where('category', $this->appliedFilters['category']);
        }

        if ($request->filled('city')) {
            $this->appliedFilters['city'] = $request->string('city')->toString();
            $query->where('city', $this->appliedFilters['city']);
        }

        if ($request->filled('date')) {
            $timezone = $this->cityTimezone();

            try {
                $day = Carbon::parse($request->string('date')->toString(), $timezone);

                $query->whereBetween('starts_at', [
                    $day->copy()->startOfDay()->utc(),
                    $day->copy()->endOfDay()->utc(),
                ]);

                $this->appliedFilters['date'] = $day->toDateString();
            } catch (\Throwable) {
                // Ignore an unparseable date and fall back to all upcoming events.
            }
        }

        if ($request->string('range')->toString() === 'weekend') {
            [$start, $end] = $this->weekendRange();

            $query->whereBetween('starts_at', [$start, $end]);
            $this->appliedFilters['range'] = 'weekend';
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
     * The upcoming weekend in the city's timezone, as a UTC range. During a
     * weekend it means the one in progress, not the next one.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function weekendRange(): array
    {
        $now = Carbon::now($this->cityTimezone());

        $start = $now->isSaturday() || $now->isSunday()
            ? $now->copy()->startOfDay()
            : $now->copy()->next(Carbon::SATURDAY)->startOfDay();

        $end = $now->isSunday()
            ? $start->copy()->endOfDay()
            : $start->copy()->addDay()->endOfDay();

        return [$start->utc(), $end->utc()];
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

    public function apiIndex(Request $request): JsonResponse
    {
        $events = $this->browseQuery($request)->paginate((int) config('eventpulse.pagination.events', 20))->withQueryString();

        $this->recordBrowse($request, $events->pluck('id')->all(), ActivitySurface::Api);

        return EventResource::collection($events)->response();
    }

    public function apiShow(Request $request, Event $event): JsonResponse
    {
        $event = $event->resolveCanonical();

        abort_if($event->is_hidden, 404);

        $this->activity->log(
            ActivityType::EventView,
            ActivitySurface::Api,
            eventId: $event->id,
            user: $request->user(),
        );

        if (($user = $request->user()) !== null) {
            $event->load([
                'reactions' => fn ($query) => $query->where('user_id', $user->id),
                'bookmarks' => fn ($query) => $query->where('user_id', $user->id),
            ]);
        }

        return (new EventResource($event))->response();
    }
}
