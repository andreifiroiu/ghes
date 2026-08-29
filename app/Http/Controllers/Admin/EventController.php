<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\EventCategory;
use App\Http\Controllers\Admin\Concerns\FiltersAdminEvents;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminEventUpdateRequest;
use App\Http\Resources\AdminEventResource;
use App\Jobs\ClassifyEventJob;
use App\Jobs\EnrichEventJob;
use App\Jobs\GeocodeEventJob;
use App\Models\Event;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

class EventController extends Controller
{
    use FiltersAdminEvents;

    private const FEATURE_BOOST = 25;

    /**
     * Columns an admin may order the list by.
     *
     * @var list<string>
     */
    private const SORTABLE = ['created_at', 'starts_at', 'title', 'popularity_score'];

    public function index(Request $request): Response
    {
        $query = Event::query();

        if ($request->filled('search')) {
            $query->whereLike('title', '%'.$request->string('search')->toString().'%');
        }

        if ($request->filled('city')) {
            $query->where('city', $request->string('city')->toString());
        }

        if ($request->filled('category')) {
            $query->where('category', $request->string('category')->toString());
        }

        if ($request->filled('source')) {
            $query->where('source', $request->string('source')->toString());
        }

        $this->applyDateRange($query, $request);

        // Merged duplicates are hidden unless explicitly asked for: they are
        // kept only so old links resolve, and listing them alongside their
        // canonical row makes every duplicate look unfixed.
        match ($request->string('status')->toString()) {
            'hidden' => $query->where('is_hidden', true)->canonical(),
            'unclassified' => $query->where('is_classified', false)->canonical(),
            'ungeocoded' => $query->where('is_geocoded', false)->canonical(),
            'merged' => $query->whereNotNull('merged_into_id'),
            default => $query->canonical(),
        };

        [$sort, $direction] = $this->sortFor($request);

        $events = $query->orderBy($sort, $direction)->paginate(20)->withQueryString();

        return Inertia::render('Admin/Events/Index', [
            'events' => AdminEventResource::collection($events),
            'filters' => $request->only([
                'search', 'city', 'category', 'source', 'status', 'date_from', 'date_to', 'sort', 'direction',
            ]),
            'categories' => array_column(EventCategory::cases(), 'value'),
            'sources' => $this->knownSources(),
            'cities' => $this->knownCities(),
        ]);
    }

    public function edit(Event $event): Response
    {
        return Inertia::render('Admin/Events/Edit', [
            'event' => new AdminEventResource($event),
            'categories' => array_column(EventCategory::cases(), 'value'),
        ]);
    }

    public function update(AdminEventUpdateRequest $request, Event $event): RedirectResponse
    {
        $event->update($request->validated());

        return redirect()->route('admin.events.index')->with('success', 'Event updated.');
    }

    public function destroy(Event $event): RedirectResponse
    {
        $event->delete();

        return redirect()->route('admin.events.index')->with('success', 'Event deleted.');
    }

    public function toggleHidden(Event $event): RedirectResponse
    {
        $event->update(['is_hidden' => ! $event->is_hidden]);

        return back()->with('success', $event->is_hidden ? 'Event hidden.' : 'Event made visible.');
    }

    public function feature(Event $event): RedirectResponse
    {
        $event->update([
            'popularity_score' => min(100, $event->popularity_score + self::FEATURE_BOOST),
        ]);

        return back()->with('success', 'Event boosted.');
    }

    public function reprocess(Request $request, Event $event): RedirectResponse
    {
        /** @var array{action: string} $validated */
        $validated = $request->validate([
            'action' => ['required', Rule::in(['classify', 'geocode', 'enrich'])],
        ]);

        // The validation rule above guarantees one of these three actions.
        match ($validated['action']) {
            'classify' => $this->queueClassify($event),
            'geocode' => $this->queueGeocode($event),
            'enrich' => $this->queueEnrich($event),
            default => throw new LogicException("Unhandled reprocess action [{$validated['action']}]."),
        };

        return back()->with('success', 'Re-processing queued.');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function sortFor(Request $request): array
    {
        $sort = $request->string('sort')->toString();
        $direction = $request->string('direction')->toString();

        return [
            in_array($sort, self::SORTABLE, true) ? $sort : 'created_at',
            $direction === 'asc' ? 'asc' : 'desc',
        ];
    }

    private function queueClassify(Event $event): void
    {
        $event->update(['is_classified' => false]);
        ClassifyEventJob::dispatch($event->id);
    }

    private function queueGeocode(Event $event): void
    {
        $event->update(['is_geocoded' => false]);
        GeocodeEventJob::dispatch($event->id);
    }

    private function queueEnrich(Event $event): void
    {
        $event->update(['is_enriched' => false]);
        EnrichEventJob::dispatch($event->id);
    }
}
