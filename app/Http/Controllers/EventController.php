<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivitySurface;
use App\Enums\ActivityType;
use App\Enums\DigestAction;
use App\Enums\Reaction;
use App\Http\Controllers\Concerns\ResolvesCity;
use App\Http\Middleware\ResolveClientSurface;
use App\Http\Resources\EventResource;
use App\Http\Responses\ApiResponse;
use App\Jobs\ProcessActivitySignalJob;
use App\Models\Event;
use App\Models\Notification;
use App\Services\Activity\ActivityLogger;
use App\Services\Events\EventSearcher;
use App\Services\Events\IcsGenerator;
use App\Services\Recommendation\RelatedEventFinder;
use App\Services\Seo\SeoManager;
use App\Services\Seo\StructuredData;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class EventController extends Controller
{
    use ResolvesCity;

    /**
     * Filter keys recorded with a search, and echoed back to the page.
     *
     * @var list<string>
     */
    private const FILTER_KEYS = ['search', 'category', 'city', 'date', 'range', 'tag', 'venue'];

    /**
     * Filters the last browseQuery() call actually applied, keyed as above.
     *
     * @var array<string, string>
     */
    private array $appliedFilters = [];

    public function __construct(
        private readonly RelatedEventFinder $relatedEventFinder,
        private readonly IcsGenerator $icsGenerator,
        private readonly ActivityLogger $activity,
        private readonly EventSearcher $searcher,
        private readonly SeoManager $seo,
        private readonly StructuredData $structuredData,
    ) {}

    public function index(Request $request): Response
    {
        $events = $this->browseQuery($request)->paginate((int) config('eventpulse.pagination.events', 20))->withQueryString();

        $this->recordBrowse($request, $events->pluck('id')->all(), ActivitySurface::EventsIndex);

        $this->applyBrowseSeo($request, $events->getCollection());

        return Inertia::render('Events/Index', [
            'events' => EventResource::collection($events),
            'filters' => $request->only(self::FILTER_KEYS),
            // The page's <h1>. Named here rather than hard-coded in the
            // component so it stays the same string as the title tag and the
            // ItemList name, which is the whole point of putting the city in
            // it: an h1 that says less than the <title> is a mismatch a
            // crawler notices.
            'city' => $this->cityLabel(),
        ]);
    }

    public function show(Request $request, Event $event): Response
    {
        $props = $this->detailProps($request, $event, ActivitySurface::EventDetail);

        // detailProps() resolved the canonical event and 404'd a hidden one, so
        // whatever it returned is the row this URL really represents.
        $this->applyDetailSeo($props['event']->resource);

        return Inertia::render('Events/Show', [
            ...$props,
            // Set only when a digest reaction redirected here, and read from
            // the query string rather than a session flash: the reader is
            // arriving from a mail webview, where the session cookie may never
            // have survived the POST that recorded the reaction. Added here and
            // not in detailProps() because apiShow() shares that method and has
            // no banner to render.
            'reactionNotice' => DigestAction::noticeFrom($request->query('reacted')),
        ]);
    }

    /**
     * Download the event as an iCalendar file.
     *
     * Web-only by design: an .ics download is a browser affordance, and the API
     * clients consume `starts_at`/`ends_at` from the resource directly.
     */
    public function calendar(Request $request, Event $event): HttpResponse
    {
        $event = $event->resolveCanonical();

        abort_if($event->is_hidden, 404);

        // Scrapers store events whose date they could not parse. Handing one to
        // a calendar would silently book a two-hour slot starting whenever the
        // button was pressed, which reads as a real commitment.
        abort_if($event->starts_at === null, 404);

        // The strongest statement of intent the product can observe: a
        // bookmark is one reversible tap, this commits a slot in someone's
        // week. Logged after the guards, so a 404 is not recorded as interest.
        $log = $this->activity->log(
            ActivityType::CalendarDownload,
            ActivitySurface::fromRequest($request->query('from'), ActivitySurface::EventDetail),
            eventId: $event->id,
            user: $request->user(),
            notificationId: $this->notificationIdFrom($request),
        );

        // Same rule as the outbound redirect: a signed-out fetch of a public
        // .ics URL is not evidence that a particular person planned anything.
        if ($log !== null && $request->user() !== null && ! $log->is_bot) {
            ProcessActivitySignalJob::dispatch($log->id, $request->user()->id);
        }

        return response($this->icsGenerator->generate($event), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$this->icsGenerator->filename($event).'"',
        ]);
    }

    /**
     * Metadata for the browse page.
     *
     * A filtered browse canonicalises to the unfiltered page. Every
     * combination of `?category=`, `?tag=`, `?venue=` and `?date=` is a
     * different URL over largely the same events, and left alone they compete
     * with each other and with the detail pages for the same terms. Only the
     * bare /events is offered for indexing; a filtered view is `noindex,
     * follow`, which still lets a crawler reach the event pages through it.
     *
     * @param  EloquentCollection<int, Event>  $events
     */
    private function applyBrowseSeo(Request $request, EloquentCollection $events): void
    {
        $city = $this->cityLabel();
        $filtered = $this->appliedFilters !== [] || $request->filled('page');

        $this->seo
            ->title("Evenimente în {$city}")
            ->description("Toate evenimentele din {$city}: concerte, teatru, expoziții, sport și viață de noapte, adunate din zeci de surse și actualizate zilnic.")
            ->canonical(route('events.index'))
            ->jsonLd('breadcrumbs', $this->structuredData->breadcrumbs([
                ['name' => 'Acasă', 'url' => route('home')],
                ['name' => 'Evenimente'],
            ]));

        if ($filtered) {
            $this->seo->robots(['noindex', 'follow']);

            return;
        }

        $this->seo->jsonLd('itemList', $this->structuredData->eventList($events, "Evenimente în {$city}"));
    }

    /**
     * Metadata for one event.
     *
     * The description falls back to a sentence built from the columns when the
     * scraped one is missing or useless. A detail page with no meta description
     * is one Google writes itself from whatever text it finds, and for an event
     * page that is usually the navigation.
     */
    private function applyDetailSeo(Event $event): void
    {
        $this->seo
            ->title($event->title)
            ->description($event->description ?? $this->fallbackDescription($event))
            ->canonical(route('events.show', $event))
            ->image($event->image_url)
            ->type('article')
            ->jsonLd('event', $this->structuredData->event($event))
            ->jsonLd('breadcrumbs', $this->structuredData->breadcrumbs([
                ['name' => 'Acasă', 'url' => route('home')],
                ['name' => 'Evenimente', 'url' => route('events.index')],
                ['name' => $event->title],
            ]));
    }

    /**
     * A description assembled from what the scraper did capture.
     *
     * Roughly a third of scraped events arrive with no description at all —
     * a ticket listing is often just a title, a date and a venue.
     */
    private function fallbackDescription(Event $event): string
    {
        $parts = [$event->title];

        if ($event->starts_at !== null) {
            $parts[] = $event->starts_at
                ->timezone($this->cityTimezone())
                ->translatedFormat('j F Y, H:i');
        }

        $where = $event->venue ?? $event->city;

        if ($where !== null && $where !== '') {
            $parts[] = $where;
        }

        return implode(' · ', $parts).'. Detalii și bilete pe Ghes.';
    }

    /**
     * Props shared by the Inertia detail page and its API twin, so a change to
     * one cannot silently leave the other behind.
     *
     * @return array{event: EventResource, relatedEvents: array<int, mixed>}
     */
    private function detailProps(Request $request, Event $event, ActivitySurface $surface): array
    {
        $event = $event->resolveCanonical();

        abort_if($event->is_hidden, 404);

        $user = $request->user();

        // Logged here rather than in show()/apiShow() so the two entry points
        // cannot drift: whichever one is called, the view is recorded against
        // the canonical event and the surface its caller named. A `?from=` on
        // the web page overrides that default — it is how a digest link
        // identifies itself, now that the digest lands here rather than jumping
        // straight to the ticket site.
        $this->activity->log(
            ActivityType::EventView,
            $surface === ActivitySurface::EventDetail
                ? ActivitySurface::fromRequest($request->query('from'), ActivitySurface::EventDetail)
                : $surface,
            eventId: $event->id,
            user: $user,
            notificationId: $this->notificationIdFrom($request),
        );

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
     * The digest a tracked link came from, if `n` names a real one.
     *
     * Shape-checked before the lookup: notification ids are Postgres uuids, so
     * querying one with arbitrary text raises rather than returning nothing,
     * and this is a public route.
     */
    private function notificationIdFrom(Request $request): ?string
    {
        $id = $request->query('n');

        if (! is_string($id) || ! Str::isUuid($id)) {
            return null;
        }

        return Notification::whereKey($id)->value('id');
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

        // The browse search reloads as the user types, so a slow typist's
        // half-written word arrives here as a search in its own right and would
        // fill the "what are people looking for" report with "j", "ja", "jaz".
        //
        // Only the *search row* is suppressed. Impressions are still recorded,
        // because those events really were rendered — and because
        // ActivityReporter divides EventClick by EventImpression while nothing
        // suppresses the click. Dropping the denominator but not the numerator
        // would push the reported click-through rate above 1.0.
        if ($filters !== [] && $this->isCommittedBrowse($request)) {
            $this->activity->log(
                ActivityType::Search,
                $surface,
                user: $user,
                context: ['filters' => $filters, 'results' => count($eventIds)],
            );
        }

        // The app can measure impressions itself (a card 60 % visible for a
        // second, reported through POST /activity) and says so by header.
        // Counting every served card as well would double its denominator.
        if (ResolveClientSurface::clientRecordsImpressions($request)) {
            return;
        }

        $this->activity->logMany(ActivityType::EventImpression, $surface, $eventIds, $user);
    }

    /**
     * Whether this browse represents a search term the user settled on, and so
     * is worth recording as one.
     *
     * Opt-out rather than opt-in on purpose: a plain link, a bookmarked URL, a
     * crawler and the API all arrive unmarked and must keep logging as they
     * always have. Only the live-search path, which knows it may be firing
     * mid-word, marks itself to suppress the search row.
     *
     * Known gap: because the debounce fires once typing pauses, a live request
     * often *is* the settled query, so `top_searches` now under-counts real
     * searches — it sees only those where the user additionally pressed Enter
     * or the button. Capturing the settled state properly needs a signal the
     * server does not have today.
     *
     * A header rather than a query parameter, because `paginate()` is followed
     * by `withQueryString()`: a `?live=1` would be copied onto
     * `events.links.next`, and every pagination click after a live search
     * would then silently record nothing. It would also sit in the address bar
     * of any URL a visitor copied out of the page, muting logging for whoever
     * opened it next.
     */
    private function isCommittedBrowse(Request $request): bool
    {
        return $request->header('X-Ghes-Live-Search') !== '1';
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
            $query->whereIn('id', $this->searcher->ids($term));
            $this->appliedFilters['search'] = $term;
        }

        if ($request->filled('category')) {
            $this->appliedFilters['category'] = $request->string('category')->toString();
            $query->where('category', $this->appliedFilters['category']);
        }

        // Tag and venue are exact facets, not free text. The autocomplete
        // offers both, and routing them through `search` would have sent them
        // to a lexical match that does not look at `tags` at all — so picking
        // the tag it had just suggested returned nothing whenever the search
        // index was unavailable.
        if ($request->filled('tag')) {
            $this->appliedFilters['tag'] = $request->string('tag')->toString();
            // Compiles to the JSONB containment operator on Postgres, served by
            // the existing events_tags_gin index, and to an EXISTS over
            // json_each() on the sqlite test connection.
            $query->whereJsonContains('tags', $this->appliedFilters['tag']);
        }

        if ($request->filled('venue')) {
            $this->appliedFilters['venue'] = $request->string('venue')->toString();
            $query->where('venue', $this->appliedFilters['venue']);
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

    public function apiIndex(Request $request): JsonResponse
    {
        $events = $this->browseQuery($request)->paginate((int) config('eventpulse.pagination.events', 20))->withQueryString();

        $this->recordBrowse($request, $events->pluck('id')->all(), ResolveClientSurface::surfaceFor($request, ActivitySurface::MobileBrowse));

        return ApiResponse::paginated(EventResource::collection($events));
    }

    public function apiShow(Request $request, Event $event): JsonResponse
    {
        // Resolved here, so a mobile detail view opened from a push can carry
        // `from=push` the same way the digest does on the web page.
        $props = $this->detailProps($request, $event, ResolveClientSurface::surfaceFor($request, ActivitySurface::MobileEventDetail));

        // One resource under `data`, with the related list embedded rather
        // than beside it: the client has exactly one parser for a detail
        // response, and a sibling key would need a second.
        return ApiResponse::item([
            ...$props['event']->resolve(),
            'related_events' => $props['relatedEvents'],
        ]);
    }
}
