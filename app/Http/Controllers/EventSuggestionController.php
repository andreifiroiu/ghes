<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\Events\EventSearcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Autocomplete suggestions for the events search box.
 *
 * Deliberately its own controller rather than a method on EventController: the
 * browse actions there run `recordBrowse()`, which writes a Search activity row
 * and an impression per rendered event. A typist produces one request per
 * settled prefix, and routing them through that method would fill the
 * "what are people looking for" report with half-typed words and feed the
 * interest-profile scorer impressions nobody chose to look at. Keeping the two
 * apart makes that structural instead of a comment somebody has to honour.
 *
 * Categories are matched in the browser, not here — the Romanian labels users
 * actually type ("Muzică", "Gastronomie") live in resources/js/lib/categories.js
 * and EventCategory is a bare backed enum, so duplicating the translation table
 * server-side would create a second source of truth for no gain.
 */
class EventSuggestionController extends Controller
{
    public function __construct(private readonly EventSearcher $searcher) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $term = trim((string) ($validated['q'] ?? ''));
        $minLength = (int) config('eventpulse.search.min_suggestion_length', 2);

        // Below the minimum the term matches most of the catalogue and ranks
        // none of it, so answer without touching the database.
        if (mb_strlen($term) < $minLength) {
            return response()->json(['events' => [], 'venues' => [], 'tags' => []]);
        }

        $limit = (int) config('eventpulse.search.suggestions_per_group', 5);

        $key = 'event-suggestions:'.md5(mb_strtolower($term)).":{$limit}";
        $ttl = (int) config('eventpulse.search.suggestion_cache_seconds', 60);

        try {
            $suggestions = Cache::remember($key, $ttl, fn (): array => $this->groups($term, $limit));
        } catch (Throwable $e) {
            // The cache itself is unavailable. A dropdown is not worth a 500 on
            // a public page, and this endpoint fires once per settled prefix —
            // so answer uncached rather than failing every keystroke.
            Log::warning('Suggestion cache unavailable.', ['exception' => $e->getMessage()]);

            $suggestions = $this->groups($term, $limit);
        }

        return response()->json($suggestions);
    }

    /**
     * The three server-computed groups, each isolated from the others.
     *
     * A group that throws returns empty instead of taking the request down.
     * This endpoint runs on every settled prefix a visitor types, so an
     * unhandled query error would mean a 500 per keystroke per visitor —
     * and, because the exception escapes `Cache::remember`, nothing would be
     * cached and the broken query would re-run on every one of them.
     *
     * Logged at `error`, not swallowed: a failing group is a defect, not an
     * expected state. Unlike the browse list, an absent suggestion asserts
     * nothing false — the dropdown simply offers less.
     *
     * @return array{events: list<array<string, mixed>>, venues: list<string>, tags: list<string>}
     */
    private function groups(string $term, int $limit): array
    {
        return [
            'events' => $this->safely('events', $term, fn (): array => $this->events($term, $limit)),
            'venues' => $this->safely('venues', $term, fn (): array => $this->venues($term, $limit)),
            'tags' => $this->safely('tags', $term, fn (): array => $this->tags($term, $limit)),
        ];
    }

    /**
     * Run one suggestion group, degrading to empty if it fails.
     *
     * @param  \Closure(): list<mixed>  $group
     * @return list<mixed>
     */
    private function safely(string $name, string $term, \Closure $group): array
    {
        try {
            return $group();
        } catch (Throwable $e) {
            Log::error("Suggestion group [{$name}] failed.", [
                'term' => $term,
                'exception' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);

            return [];
        }
    }

    /**
     * Events whose indexed text matches, as the searcher would find them.
     *
     * Resolved through EventSearcher so the dropdown can never offer an event
     * that the result list would then fail to return. More candidates than
     * needed are requested because the scoping below removes some of them —
     * the searcher deliberately knows nothing about upcoming or visible.
     *
     * @return list<array{id: string, title: string, venue: string|null, starts_at: string|null}>
     */
    private function events(string $term, int $limit): array
    {
        // A wide candidate window, because the scoping below can reject any of
        // them: ask for only a handful and a term whose best matches have all
        // finished leaves the group empty while real upcoming matches exist.
        $ids = $this->searcher->ids($term, max(50, $limit * 10));

        if ($ids === []) {
            return [];
        }

        $rank = array_flip($ids);

        return Event::query()
            ->upcoming()
            ->visible()
            ->canonical()
            ->whereIn('id', $ids)
            ->get(['id', 'title', 'venue', 'starts_at'])
            // Restore the searcher's own order rather than sorting by date.
            // Meilisearch ranks by relevance and the fallback by popularity;
            // either answers "did you mean this?" better than "soonest first",
            // which is the job of the result list, not the dropdown.
            ->sortBy(fn (Event $event): int => $rank[$event->id] ?? PHP_INT_MAX)
            ->take($limit)
            ->values()
            ->map(fn (Event $event): array => [
                'id' => $event->id,
                'title' => $event->title,
                'venue' => $event->venue,
                'starts_at' => $event->starts_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Distinct venue names matching the term, among events worth attending.
     *
     * @return list<string>
     */
    private function venues(string $term, int $limit): array
    {
        $bare = EventSearcher::likeTerm($term);

        // A term of nothing but wildcards passes the length check but strips to
        // nothing, and a `%%` pattern would match every venue in the catalogue.
        if ($bare === '') {
            return [];
        }

        /** @var list<string> $venues */
        $venues = Event::query()
            ->upcoming()
            ->visible()
            ->canonical()
            ->whereNotNull('venue')
            ->whereLike('venue', '%'.$bare.'%', caseSensitive: false)
            ->distinct()
            ->orderBy('venue')
            ->limit($limit)
            ->pluck('venue')
            ->all();

        return $venues;
    }

    /**
     * Tags matching the term.
     *
     * `tags` is a JSONB array, so the match happens in PHP over the distinct
     * tag sets of matching events rather than in SQL. The candidate set is
     * bounded by the same scoping as everything else, and a per-element JSONB
     * query would not survive the move to sqlite under test.
     *
     * @return list<string>
     */
    private function tags(string $term, int $limit): array
    {
        $needle = mb_strtolower($term);

        /** @var list<string> $tags */
        $tags = Event::query()
            ->upcoming()
            ->visible()
            ->canonical()
            // No `whereNotNull('tags')`: the column is `jsonb NOT NULL DEFAULT
            // '[]'`, so that predicate excludes nothing and would only have
            // read as a filter that was doing something.
            // Ordered so the scanned window is the soonest events rather than
            // whichever rows Postgres happened to return, which would make the
            // suggestions drift between identical requests once the upcoming
            // catalogue outgrows the window.
            ->orderBy('starts_at')
            ->limit((int) config('eventpulse.search.tag_scan_limit', 500))
            ->pluck('tags')
            ->flatten()
            ->filter(fn (mixed $tag): bool => is_string($tag) && str_contains(mb_strtolower($tag), $needle))
            // Most-used first: among matching tags, the common one is the one
            // worth offering.
            ->countBy()
            ->sortDesc()
            ->keys()
            ->take($limit)
            ->values()
            ->all();

        return $tags;
    }
}
