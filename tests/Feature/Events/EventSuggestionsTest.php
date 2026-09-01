<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\UserActivityLog;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    // Every example seeds its own events under the same terms, so a response
    // cached by a previous one would be answering the wrong question.
    Cache::flush();
});

it('suggests matching events', function () {
    $match = Event::factory()->create([
        'title' => 'Concert de jazz la Capitol',
        'starts_at' => now()->addWeek(),
    ]);
    Event::factory()->create(['title' => 'Meci de fotbal', 'starts_at' => now()->addWeek()]);

    $response = $this->getJson('/events/suggestions?q=jazz')->assertOk();

    expect($response->json('events'))->toHaveCount(1)
        ->and($response->json('events.0.id'))->toBe($match->id)
        ->and($response->json('events.0.title'))->toBe('Concert de jazz la Capitol');
});

it('suggests matching venues', function () {
    Event::factory()->create(['venue' => 'Teatrul Merlin', 'starts_at' => now()->addWeek()]);
    Event::factory()->create(['venue' => 'Sala Capitol', 'starts_at' => now()->addWeek()]);

    $response = $this->getJson('/events/suggestions?q=Merlin')->assertOk();

    expect($response->json('venues'))->toBe(['Teatrul Merlin']);
});

it('suggests matching tags', function () {
    Event::factory()->create([
        'tags' => ['live-music', 'jazz'],
        'title' => 'Ceva',
        'starts_at' => now()->addWeek(),
    ]);

    $response = $this->getJson('/events/suggestions?q=live')->assertOk();

    expect($response->json('tags'))->toBe(['live-music']);
});

it('caps each group', function () {
    Event::factory()->count(12)->create([
        'title' => 'Concert de jazz',
        'starts_at' => now()->addWeek(),
    ]);

    $response = $this->getJson('/events/suggestions?q=jazz')->assertOk();

    expect($response->json('events'))->toHaveCount(5);
});

it('ignores a term shorter than the minimum without querying', function () {
    Event::factory()->create(['title' => 'Jazz', 'starts_at' => now()->addWeek()]);

    $response = $this->getJson('/events/suggestions?q=j')->assertOk();

    expect($response->json())->toBe(['events' => [], 'venues' => [], 'tags' => []]);
});

it('excludes past, hidden and merged events', function () {
    Event::factory()->create(['title' => 'Concert de jazz trecut', 'starts_at' => now()->subWeek()]);
    Event::factory()->create([
        'title' => 'Concert de jazz ascuns',
        'starts_at' => now()->addWeek(),
        'is_hidden' => true,
    ]);
    $canonical = Event::factory()->create(['title' => 'Canonic', 'starts_at' => now()->addWeek()]);
    Event::factory()->create([
        'title' => 'Concert de jazz duplicat',
        'starts_at' => now()->addWeek(),
        'merged_into_id' => $canonical->id,
    ]);

    $response = $this->getJson('/events/suggestions?q=jazz')->assertOk();

    expect($response->json('events'))->toBe([]);
});

it('is available to guests', function () {
    Event::factory()->create(['title' => 'Concert de jazz', 'starts_at' => now()->addWeek()]);

    $this->getJson('/events/suggestions?q=jazz')->assertOk();
});

it('records no activity, however much the visitor types', function () {
    Event::factory()->create(['title' => 'Concert de jazz', 'starts_at' => now()->addWeek()]);

    foreach (['ja', 'jaz', 'jazz'] as $prefix) {
        $this->getJson("/events/suggestions?q={$prefix}")->assertOk();
    }

    // A suggestion lookup is not a search anyone performed, and the events it
    // names were never rendered — logging either would corrupt the search
    // report and feed the profile scorer impressions nobody chose to see.
    expect(UserActivityLog::query()->count())->toBe(0);
});

it('rejects an over-long term', function () {
    $this->getJson('/events/suggestions?q='.str_repeat('a', 101))
        ->assertStatus(422);
});

it('does not treat a wildcard-only term as a match-everything query', function () {
    Event::factory()->count(3)->create([
        'venue' => 'Teatrul Merlin',
        'starts_at' => now()->addWeek(),
    ]);

    // "%%" clears the two-character minimum but strips to nothing. Without a
    // guard the venue pattern collapses to '%%' and suggests the whole
    // catalogue back as matches for a query that matched nothing.
    $response = $this->getJson('/events/suggestions?q=%25%25')->assertOk();

    expect($response->json())->toBe(['events' => [], 'venues' => [], 'tags' => []]);
});
