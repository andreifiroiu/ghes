<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\Event;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Meilisearch\Exceptions\CommunicationException;
use Meilisearch\Exceptions\TimeOutException;
use Throwable;

/**
 * Resolves a free-text term to a set of candidate event ids.
 *
 * Single owner of "term -> ids" so the browse list and the suggestion dropdown
 * can never disagree: a suggestion the dropdown offers is, by construction,
 * one the result list will also find.
 *
 * Two engines sit behind it. Meilisearch (via Scout) is asked first — it is
 * typo-tolerant and folds diacritics, which matters for Romanian. A LIKE match
 * against Postgres answers whenever it cannot, and calling that a mere safety
 * net would understate how often it runs:
 *
 *  - `SCOUT_DRIVER=null` in phpunit.xml means the Scout path returns nothing at
 *    all under test, so without a fallback every search assertion would be
 *    quietly asserting on an empty set.
 *  - Meilisearch is not running in the dev worktrees.
 *  - A Meilisearch outage in production should degrade search quality, not take
 *    the public browse page down with it — the same posture EventPipeline
 *    already takes when it wraps ingestion in `withoutSyncingToSearch()`.
 *  - That same wrapping means the index is **not reliably populated**: nothing
 *    in the ingestion path indexes a new event and no `scout:import` is
 *    scheduled, so today the database answers far more often than "fallback"
 *    suggests. Until that is fixed, an empty Scout result is treated as no
 *    answer rather than as no matches — see `ids()`.
 */
class EventSearcher
{
    /**
     * Columns the fallback matches against, widest-signal first.
     *
     * @var list<string>
     */
    private const FALLBACK_COLUMNS = ['title', 'venue', 'description'];

    /**
     * Cache flag raised while the search index is known to be unreachable.
     */
    private const DEGRADED_KEY = 'events:search:degraded';

    /**
     * Candidate event ids for the term, most relevant first where the engine
     * ranks them.
     *
     * Callers still apply their own scoping — these ids are candidates, not a
     * result set. The database path additionally narrows to upcoming, visible
     * and canonical events before taking its window, because that window is
     * finite and would otherwise be spent on events nobody can attend.
     *
     * The Scout path cannot do the same: the Meilisearch index carries no
     * `starts_at` and has no filterable attributes configured, so its window
     * can still be diluted by past events. That asymmetry is the reason the
     * follow-up issue on index settings matters.
     *
     * @return list<string>
     */
    public function ids(string $term, ?int $limit = null): array
    {
        $term = trim($term);
        $limit ??= (int) config('eventpulse.search.max_candidates', 300);

        if ($term === '' || $limit < 1) {
            return [];
        }

        $ids = $this->scoutIds($term, $limit);

        // An empty result is treated as "no answer", not as "no matches".
        //
        // The index is not reliably populated: every write in the ingestion
        // path is wrapped in `withoutSyncingToSearch()` (EventPipeline,
        // EventClassifier, EventMerger) and nothing schedules `scout:import`,
        // so a freshly scraped event is absent from Meilisearch until someone
        // imports by hand. A stale index does not throw — it answers 200 with a
        // short list — so the circuit breaker never trips and `?? ` on a null
        // would never fire. Without this, a search for "jazz" would report
        // "nothing matches" while three jazz concerts sat in the same table.
        //
        // The cost is one extra query on a genuinely empty search; the DB then
        // returns nothing either. See the follow-up issue on fixing ingestion,
        // which is the real cure.
        if ($ids !== null && $ids !== []) {
            return $ids;
        }

        return $this->fallbackIds($term, $limit);
    }

    /**
     * Ids from Meilisearch, or null when the engine cannot answer and the
     * caller should fall back.
     *
     * `take()` is load-bearing rather than an optimisation: Scout's
     * MeilisearchEngine passes `hitsPerPage => $builder->limit` through
     * `array_filter()`, so leaving the limit null drops the key entirely and
     * Meilisearch applies its own default of 20 hits. With a page size of 18
     * that silently truncated every search to a page and a half.
     *
     * @return list<string>|null
     */
    private function scoutIds(string $term, int $limit): ?array
    {
        if (! $this->indexUsable()) {
            return null;
        }

        try {
            /** @var list<string> $ids */
            $ids = Event::search($term)->take($limit)->keys()->all();

            return $ids;
        } catch (Throwable $e) {
            $this->markDegraded($e);

            return null;
        }
    }

    /**
     * Whether it is worth asking the search index at all.
     *
     * The cache lookup is guarded because the breaker must not become a second
     * thing that can fail: Redis is the cache broker, and an unguarded
     * `Cache::has()` would throw straight out of the public browse page — a 500
     * for guests caused by the very mechanism added to keep search up. If the
     * cache cannot answer, assume the index is worth trying.
     */
    private function indexUsable(): bool
    {
        if (config('scout.driver') === null) {
            return false;
        }

        try {
            return ! Cache::has(self::DEGRADED_KEY);
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Remember that the index is unreachable, and say so once.
     *
     * Both halves matter now that search runs as the user types. Without the
     * flag, an outage costs a failed connection attempt and a log line on every
     * keystroke of every visitor — the search outage becomes a log outage, and
     * each request still pays the connection timeout before falling back.
     * `Cache::add()` returns false when the flag is already set, so the warning
     * is written on the transition rather than on every miss.
     */
    private function markDegraded(Throwable $e): void
    {
        $ttl = (int) config('eventpulse.search.degraded_ttl_seconds', 60);

        // A connection or timeout failure is transient and self-heals; anything
        // else means the index answered and refused — a wrong prefix, a bad key,
        // a malformed query — which is a defect a person has to fix, not an
        // outage to wait out. Logging both at `warning` would bury a permanent
        // misconfiguration in the noise of a blip.
        $transient = $e instanceof CommunicationException || $e instanceof TimeOutException;

        try {
            $firstOfThisOutage = Cache::add(self::DEGRADED_KEY, true, $ttl);
        } catch (Throwable) {
            // The breaker is unavailable, so this call cannot be deduplicated.
            // Say it anyway — losing the reason search degraded is worse than
            // repeating it.
            $firstOfThisOutage = true;
        }

        if (! $firstOfThisOutage) {
            return;
        }

        $context = [
            'exception' => $e->getMessage(),
            // Without the class, "connection refused" and "index not found" are
            // the same line in the log and only one of them is worth waking for.
            'exception_class' => $e::class,
        ];

        if ($transient) {
            Log::warning('Event search index unreachable; using the database fallback.', $context);

            return;
        }

        Log::error('Event search index rejected the query; using the database fallback.', $context);
    }

    /**
     * Ids from a case-insensitive LIKE across the text columns.
     *
     * `whereLike(..., caseSensitive: false)` compiles to ILIKE on Postgres and
     * to LIKE on the sqlite test connection, so the tests exercise the same
     * code that runs in production rather than a parallel branch.
     *
     * @return list<string>
     */
    private function fallbackIds(string $term, int $limit): array
    {
        $bare = self::likeTerm($term);

        // A term made only of wildcards strips down to nothing, and a `%%`
        // pattern would match the entire table — the opposite of the narrowing
        // the caller asked for.
        if ($bare === '') {
            return [];
        }

        $pattern = '%'.$bare.'%';

        /** @var list<string> $ids */
        $ids = Event::query()
            // Scoped here as well as by the caller, because the candidate
            // window is finite. `CleanupExpiredEventsJob` keeps reacted-to
            // events for 90 days, so the table carries a long past tail — and
            // past events have had longer to accumulate the popularity the
            // ordering below uses. Without this, a common term could fill all
            // 300 slots with finished events and the caller would show a
            // handful of upcoming ones as though that were all there was.
            ->upcoming()
            ->visible()
            ->canonical()
            ->where(function ($query) use ($pattern): void {
                foreach (self::FALLBACK_COLUMNS as $column) {
                    $query->orWhereLike($column, $pattern, caseSensitive: false);
                }
            })
            // No relevance to order by, so give the ranking some meaning:
            // popularity first, and a deterministic tiebreak so pagination
            // cannot show the same event on two consecutive pages.
            ->orderByDesc('popularity_score')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();

        return $ids;
    }

    /**
     * Remove LIKE wildcards from a user-supplied term.
     *
     * Shared rather than reimplemented by each caller: this is the rule that
     * stops a bare "%" matching the entire table, and two copies of it is one
     * copy waiting to be forgotten.
     *
     * Stripping rather than backslash-escaping is deliberate: neither the
     * Postgres nor the SQLite grammar emits an ESCAPE clause for `whereLike`,
     * so an escaped `\%` means "any sequence" on Postgres (backslash is its
     * default escape character) but "a literal backslash, then any sequence"
     * on the sqlite test connection. Stripping behaves identically on both, at
     * the cost of treating a searched-for "%" as absent rather than literal.
     *
     * Returns an empty string when nothing survives, which callers must treat
     * as "match nothing" — a `%%` pattern would match everything instead.
     */
    public static function likeTerm(string $term): string
    {
        return str_replace(['%', '_'], '', $term);
    }
}
