<?php

declare(strict_types=1);

use App\Models\Event;
use App\Services\Events\EventSearcher;
use Illuminate\Support\Facades\Cache;
use Laravel\Scout\Builder;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\NullEngine;

/** A Scout engine that records the limit the builder asked for. */
class RecordingEngine extends NullEngine
{
    public static ?int $limit = -1;

    public function search(Builder $builder)
    {
        self::$limit = $builder->limit;

        return parent::search($builder);
    }
}

/** A Scout engine that behaves like an unreachable Meilisearch. */
class ThrowingEngine extends NullEngine
{
    public int $calls = 0;

    public function search(Builder $builder)
    {
        $this->calls++;

        throw new RuntimeException('connection refused');
    }
}

/**
 * These run against the database fallback: phpunit.xml sets SCOUT_DRIVER=null,
 * so the Scout path returns nothing and EventSearcher falls through. That is
 * the point of the fallback existing — before it, every one of these assertions
 * would have been passing over an empty result set.
 *
 * Lives under tests/Feature rather than tests/Unit because the service reads
 * config(), which needs a booted application.
 */
beforeEach(function () {
    $this->searcher = app(EventSearcher::class);
});

it('matches on the title', function () {
    $match = Event::factory()->create(['title' => 'Concert de jazz la Capitol']);
    Event::factory()->create(['title' => 'Meci de fotbal']);

    expect($this->searcher->ids('jazz'))->toBe([$match->id]);
});

it('matches on the venue', function () {
    $match = Event::factory()->create(['title' => 'Seară deschisă', 'venue' => 'Teatrul Merlin']);
    Event::factory()->create(['title' => 'Altceva', 'venue' => 'Sala Capitol']);

    expect($this->searcher->ids('Merlin'))->toBe([$match->id]);
});

it('matches on the description', function () {
    $match = Event::factory()->create([
        'title' => 'Seară de film',
        'description' => 'O retrospectivă dedicată regizorului Tarkovski.',
    ]);
    Event::factory()->create(['title' => 'Altceva', 'description' => 'Fără legătură.']);

    expect($this->searcher->ids('Tarkovski'))->toBe([$match->id]);
});

it('matches regardless of case', function () {
    $event = Event::factory()->create(['title' => 'Concert de JAZZ']);

    expect($this->searcher->ids('jazz'))->toBe([$event->id]);
});

it('returns nothing for a blank term', function () {
    Event::factory()->create(['title' => 'Concert de jazz']);

    expect($this->searcher->ids('   '))->toBe([]);
});

it('respects the limit', function () {
    Event::factory()->count(5)->create(['title' => 'Concert de jazz']);

    expect($this->searcher->ids('jazz', 3))->toHaveCount(3);
});

it('treats a wildcard as a literal rather than matching everything', function () {
    Event::factory()->count(3)->create(['title' => 'Concert de jazz']);

    // A bare "%" reaching LIKE unescaped would return the whole table. The
    // wildcard is stripped, so this searches for an empty term instead.
    expect($this->searcher->ids('%'))->toBe([]);
});

it('returns more candidates than one page, so a search can paginate', function () {
    // Note this exercises the database path (SCOUT_DRIVER=null), so it guards
    // the fallback's own limit — not the Scout `take()`. The example below is
    // the one that pins that.
    Event::factory()->count(25)->create(['title' => 'Concert de jazz']);

    expect($this->searcher->ids('jazz'))->toHaveCount(25);
});

it('asks the index for an explicit limit rather than accepting its default', function () {
    Cache::flush();
    RecordingEngine::$limit = -1;
    config(['scout.driver' => 'meilisearch']);
    app(EngineManager::class)->extend('meilisearch', fn () => new RecordingEngine);

    app(EventSearcher::class)->ids('jazz', 250);

    // Scout's MeilisearchEngine passes `hitsPerPage => $builder->limit` through
    // array_filter(), so a null limit is dropped and Meilisearch falls back to
    // its own default of 20 hits — which silently truncated every search to a
    // page and a half. Deleting the take() in scoutIds() fails here, and only
    // here: every other example in this file runs the database path.
    expect(RecordingEngine::$limit)->toBe(250);
});

it('falls back to the database when the search index throws', function () {
    Cache::flush();
    config(['scout.driver' => 'meilisearch']);
    app(EngineManager::class)->extend('meilisearch', fn () => new ThrowingEngine);

    $match = Event::factory()->create(['title' => 'Concert de jazz']);
    Event::factory()->create(['title' => 'Meci de fotbal']);

    // An unreachable index must degrade search quality, not take the public
    // browse page down with it.
    expect(app(EventSearcher::class)->ids('jazz'))->toBe([$match->id]);
});

it('stops calling the index while it is known to be down', function () {
    Cache::flush();
    config(['scout.driver' => 'meilisearch']);
    $engine = new ThrowingEngine;
    app(EngineManager::class)->extend('meilisearch', fn () => $engine);

    $searcher = app(EventSearcher::class);
    $searcher->ids('jazz');
    $searcher->ids('rock');
    $searcher->ids('teatru');

    // Without the circuit breaker, live search would mean one failed
    // connection and one log line per keystroke, per visitor, for the whole
    // outage — each of them paying the connection timeout first.
    //
    // Asserting the cache flag too, not just the call count: the count alone
    // would still pass if the flag were a private property on the instance,
    // which would not survive the request boundary the breaker exists for.
    expect($engine->calls)->toBe(1)
        ->and(Cache::has('events:search:degraded'))->toBeTrue();
});

it('falls back when the index answers but returns nothing', function () {
    Cache::flush();
    config(['scout.driver' => 'meilisearch']);
    app(EngineManager::class)->extend('meilisearch', fn () => new NullEngine);

    $match = Event::factory()->create(['title' => 'Concert de jazz']);

    // Nothing in the ingestion path indexes an event and no scout:import is
    // scheduled, so a reachable index can be empty or stale. It answers 200
    // with no hits rather than throwing, so the breaker never trips — treating
    // that as "no matches" would tell the user nothing exists while the row
    // sits in the same table.
    expect(app(EventSearcher::class)->ids('jazz'))->toBe([$match->id]);
});
