<?php

declare(strict_types=1);

use App\Models\Event;

it('derives city_slug from city on create', function () {
    $event = Event::factory()->create(['city' => 'Timișoara']);

    expect($event->fresh()->city_slug)->toBe('timisoara');
});

it('recomputes city_slug when the city is corrected', function () {
    // The admin edit form writes plain `city`. Before the model hook the slug
    // kept pointing at the old city and the event silently vanished from every
    // personalised list while still looking right in the admin table — and the
    // nightly backfill only revisits NULL slugs, so it never healed.
    $event = Event::factory()->create(['city' => 'Bucuresti']);

    expect($event->fresh()->city_slug)->toBe('bucuresti');

    $event->update(['city' => 'Timișoara']);

    expect($event->fresh()->city_slug)->toBe('timisoara');
});

it('fills in a missing city_slug on save', function () {
    $event = Event::factory()->create(['city' => 'Timișoara']);

    // Simulate a pre-backfill row.
    Event::query()->where('id', $event->id)->update(['city_slug' => null]);

    $event->refresh()->touch();

    expect($event->fresh()->city_slug)->toBe('timisoara');
});

it('nulls city_slug for a city that cannot be slugged', function () {
    $event = Event::factory()->create(['city' => '!!!']);

    expect($event->fresh()->city_slug)->toBeNull();
});
