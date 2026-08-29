<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\DTOs\DuplicateGroup;
use App\Http\Controllers\Admin\Concerns\FiltersAdminEvents;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminEventResource;
use App\Models\Event;
use App\Services\Processing\DuplicateFinder;
use App\Services\Processing\EventMerger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Review and merge duplicate events by hand.
 *
 * The nightly `eventpulse:dedupe-events` command merges the same groups
 * unattended; this screen exists for the cases an operator would rather see
 * first — most usefully the scored pass, which is off by default there.
 */
class DuplicateEventController extends Controller
{
    use FiltersAdminEvents;

    /**
     * How many groups to render at once. Scoring is O(n²) inside each
     * same-day bucket, so this is a page of work, not a page of rows.
     */
    private const GROUP_LIMIT = 25;

    public function index(Request $request, DuplicateFinder $finder): Response
    {
        $fuzzy = $request->boolean('fuzzy');

        $groups = $finder->find($this->scope($request), $fuzzy, self::GROUP_LIMIT);

        return Inertia::render('Admin/Events/Duplicates', [
            'groups' => $groups->map(fn (DuplicateGroup $group): array => [
                'key' => $group->key,
                'reason' => $group->reason,
                'score' => round($group->score, 2),
                'events' => AdminEventResource::collection($group->events)->resolve($request),
            ])->all(),
            'filters' => [
                // `fuzzy` is echoed back as a real boolean: a `fuzzy=false`
                // query string would otherwise reach the page as the truthy
                // string "false".
                'fuzzy' => $fuzzy,
                'city' => $request->string('city')->toString(),
                'date_from' => $request->string('date_from')->toString(),
                'date_to' => $request->string('date_to')->toString(),
            ],
            'cities' => $this->knownCities(),
            'limit' => self::GROUP_LIMIT,
            'truncated' => $groups->count() >= self::GROUP_LIMIT,
        ]);
    }

    /**
     * Fold one or more events into a canonical one.
     */
    public function store(Request $request, EventMerger $merger): RedirectResponse
    {
        /** @var array{canonical_id: string, duplicate_ids: list<string>} $validated */
        $validated = $request->validate([
            'canonical_id' => ['required', 'uuid', Rule::exists('events', 'id')],
            'duplicate_ids' => ['required', 'array', 'min:1'],
            'duplicate_ids.*' => ['uuid', 'different:canonical_id', Rule::exists('events', 'id')],
        ]);

        $canonical = Event::findOrFail($validated['canonical_id']);

        if ($canonical->merged_into_id !== null) {
            return back()->with('error', 'That event is itself a merged duplicate — pick the surviving event instead.');
        }

        // Already-merged rows are skipped rather than rejected: two admins can
        // easily act on the same group, and the second one merging nothing is
        // the right outcome, not an error.
        $duplicates = Event::query()
            ->canonical()
            ->whereIn('id', array_diff($validated['duplicate_ids'], [$canonical->id]))
            ->get();

        foreach ($duplicates as $duplicate) {
            $merger->mergeInto($canonical, $duplicate);
        }

        if ($duplicates->isEmpty()) {
            return back()->with('error', 'Nothing to merge — those events were already merged.');
        }

        return back()->with('success', sprintf(
            'Merged %d %s into "%s".',
            $duplicates->count(),
            $duplicates->count() === 1 ? 'event' : 'events',
            $canonical->title,
        ));
    }

    /**
     * The scope duplicates are looked for in.
     *
     * @return Builder<Event>
     */
    private function scope(Request $request): Builder
    {
        $query = Event::query();

        if ($request->filled('city')) {
            $query->where('city', $request->string('city')->toString());
        }

        $this->applyDateRange($query, $request);

        return $query;
    }
}
