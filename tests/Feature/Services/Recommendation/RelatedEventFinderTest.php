<?php

declare(strict_types=1);

use App\Enums\EventCategory;
use App\Enums\Reaction;
use App\Models\Event;
use App\Models\EventBookmark;
use App\Models\User;
use App\Models\UserEventReaction;
use App\Services\Recommendation\RelatedEventFinder;

/**
 * A candidate event in the subject's city.
 *
 * The city is spelled out rather than left to the factory, which defaults to
 * Bucharest: the finder refuses cross-city matches, so a candidate that does
 * not say where it is would silently test nothing.
 *
 * @param  array<string, mixed>  $attributes
 */
function candidate(array $attributes = []): Event
{
    return Event::factory()->create($attributes + [
        'city' => 'Timișoara',
        'category' => EventCategory::Music,
        'tags' => [],
        'venue' => 'Alt Loc',
        'starts_at' => now()->addDays(4),
    ]);
}

beforeEach(function () {
    $this->finder = app(RelatedEventFinder::class);

    $this->subject = Event::factory()->create([
        'category' => EventCategory::Music,
        'tags' => ['jazz', 'live-music'],
        'venue' => 'Casa Tineretului',
        'city' => 'Timișoara',
        'starts_at' => now()->addDays(3),
    ]);
});

it('finds events sharing the subject category', function () {
    $sameCategory = candidate();
    candidate(['category' => EventCategory::Sports]);

    $related = $this->finder->find($this->subject);

    expect($related->pluck('id')->all())->toBe([$sameCategory->id]);
});

it('ranks a shared-tag, same-venue event above a bare category match', function () {
    $bareCategory = candidate();

    $strongMatch = candidate([
        'tags' => ['jazz', 'live-music'],
        'venue' => 'Casa Tineretului',
        'starts_at' => now()->addDays(5),
    ]);

    $related = $this->finder->find($this->subject);

    expect($related->pluck('id')->all())->toBe([$strongMatch->id, $bareCategory->id]);
});

it('matches on a shared tag alone, across categories', function () {
    $taggedOnly = candidate([
        'category' => EventCategory::Community,
        'tags' => ['jazz'],
    ]);

    expect($this->finder->find($this->subject)->pluck('id')->all())
        ->toContain($taggedOnly->id);
});

it('matches on a shared venue alone, across categories', function () {
    $sameVenue = candidate([
        'category' => EventCategory::Business,
        'venue' => 'Casa Tineretului',
    ]);

    expect($this->finder->find($this->subject)->pluck('id')->all())
        ->toContain($sameVenue->id);
});

it('never includes the subject event itself', function () {
    candidate(['tags' => ['jazz']]);

    expect($this->finder->find($this->subject)->pluck('id')->all())
        ->not->toContain($this->subject->id);
});

it('excludes hidden, merged and past events', function () {
    candidate(['tags' => ['jazz'], 'is_hidden' => true]);

    // The survivor of the merge is itself unrelated, so only the merged
    // duplicate's exclusion is under test here.
    $canonical = candidate([
        'category' => EventCategory::Sports,
        'starts_at' => now()->addDays(6),
    ]);
    Event::factory()->merged($canonical)->create([
        'category' => EventCategory::Music,
        'city' => 'Timișoara',
        'tags' => ['jazz'],
        'starts_at' => now()->addDays(4),
    ]);

    Event::factory()->past()->create([
        'category' => EventCategory::Music,
        'city' => 'Timișoara',
        'tags' => ['jazz'],
    ]);

    expect($this->finder->find($this->subject))->toBeEmpty();
});

it('excludes events the user dismissed as not interested', function () {
    $user = User::factory()->create();
    $dismissed = candidate(['tags' => ['jazz']]);

    UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $dismissed->id,
        'reaction' => Reaction::NotInterested,
    ]);

    expect($this->finder->find($this->subject, $user))->toBeEmpty();

    // Another user's dismissal must not affect this one.
    expect($this->finder->find($this->subject)->pluck('id')->all())
        ->toBe([$dismissed->id]);
});

it('eager-loads the user reaction and bookmark state', function () {
    $user = User::factory()->create();
    $found = candidate(['tags' => ['jazz']]);

    UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $found->id,
        'reaction' => Reaction::Interested,
    ]);
    EventBookmark::factory()->create([
        'user_id' => $user->id,
        'event_id' => $found->id,
    ]);

    $related = $this->finder->find($this->subject, $user);

    expect($related->first()->relationLoaded('reactions'))->toBeTrue()
        ->and($related->first()->relationLoaded('bookmarks'))->toBeTrue()
        ->and($related->first()->reactions->first()->reaction)->toBe(Reaction::Interested)
        ->and($related->first()->bookmarks)->toHaveCount(1);
});

it('returns nothing when no event shares any signal', function () {
    candidate(['category' => EventCategory::Sports, 'tags' => ['outdoor']]);

    expect($this->finder->find($this->subject))->toBeEmpty();
});

it('still scores when the points config is missing or partial', function () {
    $found = candidate(['tags' => ['jazz']]);

    // A config cache written before this key existed, and a partial override.
    foreach ([null, ['category' => 3]] as $points) {
        config(['eventpulse.recommendation.related.points' => $points]);

        expect($this->finder->find($this->subject)->pluck('id')->all())
            ->toBe([$found->id]);
    }
});

it('honours the configured result limit', function () {
    Event::factory()->count(5)->create([
        'category' => EventCategory::Music,
        'city' => 'Timișoara',
        'tags' => ['jazz'],
        'starts_at' => now()->addDays(4),
    ]);

    config(['eventpulse.recommendation.related.limit' => 2]);

    expect($this->finder->find($this->subject))->toHaveCount(2);
});

describe('city scoping', function () {
    it('never suggests an event in another city', function () {
        // An otherwise perfect match: same category, same tags, same venue
        // name, days apart. Only the city differs, and that is disqualifying —
        // nobody in Timișoara is served by a concert in Cluj.
        candidate([
            'city' => 'Cluj-Napoca',
            'tags' => ['jazz', 'live-music'],
            'venue' => 'Casa Tineretului',
        ]);

        expect($this->finder->find($this->subject))->toBeEmpty();
    });

    it('treats cities differing only by diacritics as the same city', function () {
        $unaccented = candidate(['city' => 'Timisoara', 'tags' => ['jazz']]);

        expect($this->finder->find($this->subject)->pluck('id')->all())
            ->toBe([$unaccented->id]);
    });

    it('keeps an event whose city could not be determined', function () {
        // Null is "we could not place it", not "somewhere else", and the
        // sources are city-scoped — dropping these would shrink the strip for
        // every event whose city failed to parse.
        $unplaced = candidate(['city' => null, 'tags' => ['jazz']]);

        expect($this->finder->find($this->subject)->pluck('id')->all())
            ->toBe([$unplaced->id]);
    });

    it('ranks a confirmed city match above an unplaced event', function () {
        $unplaced = candidate(['city' => null, 'tags' => ['jazz']]);
        $placed = candidate(['tags' => ['jazz'], 'starts_at' => now()->addDays(5)]);

        expect($this->finder->find($this->subject)->pluck('id')->all())
            ->toBe([$placed->id, $unplaced->id]);
    });

    it('cannot scope when the subject itself has no city', function () {
        $subject = Event::factory()->create([
            'category' => EventCategory::Music,
            'tags' => ['jazz'],
            'city' => null,
            'starts_at' => now()->addDays(3),
        ]);

        $elsewhere = candidate(['city' => 'Cluj-Napoca', 'tags' => ['jazz']]);

        expect($this->finder->find($subject)->pluck('id')->all())
            ->toContain($elsewhere->id);
    });
});
