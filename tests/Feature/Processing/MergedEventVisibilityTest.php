<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\User;
use App\Services\Recommendation\DiscoveryEngine;
use App\Services\Recommendation\RecommendationEngine;

/**
 * A merged duplicate is kept in the database so historical reactions and
 * already-sent digests keep resolving. It must never surface anywhere a user
 * looks, or the duplicate they complained about is still on screen.
 */
beforeEach(function () {
    // These assertions are about which events reach the page, not about assets.
    $this->withoutVite();

    $this->canonical = Event::factory()->create([
        'title' => 'Concert Phoenix',
        'city' => 'Timișoara',
        'starts_at' => now()->addWeek(),
        'is_classified' => true,
    ]);

    $this->merged = Event::factory()->create([
        'title' => 'Concert Phoenix - Live in Timisoara',
        'city' => 'Timișoara',
        'starts_at' => now()->addWeek(),
        'is_classified' => true,
        'merged_into_id' => $this->canonical->id,
    ]);
});

it('hides merged events from the browse listing', function () {
    $ids = Event::canonical()->upcoming()->pluck('id');

    expect($ids)->toContain($this->canonical->id)
        ->and($ids)->not->toContain($this->merged->id);
});

it('hides merged events from the browse page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('events.index'));

    $response->assertOk();

    $ids = collect($response->viewData('page')['props']['events']['data'] ?? [])->pluck('id');

    expect($ids)->toContain($this->canonical->id)
        ->and($ids)->not->toContain($this->merged->id);
});

it('hides merged events from recommendations', function () {
    $user = User::factory()->create(['city' => 'Timișoara']);

    $batch = app(RecommendationEngine::class)->recommend($user, 10);

    expect($batch->recommendedEventIds)->not->toContain($this->merged->id)
        ->and($batch->discoveryEventIds)->not->toContain($this->merged->id);
});

it('hides merged events from discovery', function () {
    $user = User::factory()->create(['city' => 'Timișoara']);

    $events = app(DiscoveryEngine::class)->discoverForUser($user, 10);

    expect(collect($events)->pluck('id'))->not->toContain($this->merged->id);
});

it('keeps merged events out of the search index', function () {
    expect($this->canonical->shouldBeSearchable())->toBeTrue()
        ->and($this->merged->shouldBeSearchable())->toBeFalse();
});

it('resolves a link to a merged event to the surviving one', function () {
    // Links in already-sent digests point at ids that may since have been
    // merged away; they must land on the surviving event, not a stale copy.
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('events.show', $this->merged));

    $response->assertOk();

    expect($response->viewData('page')['props']['event']['id'])->toBe($this->canonical->id);
});
