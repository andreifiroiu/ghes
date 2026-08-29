<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Reaction;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Services\Events\IcsGenerator;
use App\Services\Recommendation\RelatedEventFinder;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class EventController extends Controller
{
    public function __construct(
        private readonly RelatedEventFinder $relatedEventFinder,
        private readonly IcsGenerator $icsGenerator,
    ) {}

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
        return Inertia::render('Events/Show', $this->detailProps($request, $event));
    }

    /**
     * Download the event as an iCalendar file.
     *
     * Web-only by design: an .ics download is a browser affordance, and the API
     * clients consume `starts_at`/`ends_at` from the resource directly.
     */
    public function calendar(Request $request, Event $event): HttpResponse
    {
        $event = $this->resolveCanonical($event);

        abort_if($event->is_hidden, 404);

        // Scrapers store events whose date they could not parse. Handing one to
        // a calendar would silently book a two-hour slot starting whenever the
        // button was pressed, which reads as a real commitment.
        abort_if($event->starts_at === null, 404);

        return response($this->icsGenerator->generate($event), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$this->icsGenerator->filename($event).'"',
        ]);
    }

    /**
     * Props shared by the Inertia detail page and its API twin, so a change to
     * one cannot silently leave the other behind.
     *
     * @return array{event: EventResource, relatedEvents: array<int, mixed>}
     */
    private function detailProps(Request $request, Event $event): array
    {
        $event = $this->resolveCanonical($event);

        abort_if($event->is_hidden, 404);

        $user = $request->user();

        // `sources` is loaded for guests too — the detail page lists every
        // provider that reported the event, not only the one it was scraped
        // under.
        $event->load(['sources' => fn ($query) => $query->orderBy('source')]);

        if ($user !== null) {
            $event->load([
                'reactions' => fn ($query) => $query->where('user_id', $user->id),
                'bookmarks' => fn ($query) => $query->where('user_id', $user->id),
            ]);
        }

        return [
            'event' => new EventResource($event),
            // `resolve()` flattens away the `data` envelope: this is a plain
            // list, not a paginator, so the page consumes it as an array.
            'relatedEvents' => EventResource::collection(
                $this->relatedEventFinder->find($event, $user),
            )->resolve(),
        ];
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

        return EventResource::collection($events)->response();
    }

    public function apiShow(Request $request, Event $event): JsonResponse
    {
        $props = $this->detailProps($request, $event);

        return response()->json([
            'data' => $props['event']->resolve(),
            'relatedEvents' => $props['relatedEvents'],
        ]);
    }
}
