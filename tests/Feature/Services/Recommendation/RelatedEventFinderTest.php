<?php

declare(strict_types=1);

use App\Enums\EventCategory;
use App\Enums\Reaction;
use App\Models\Event;
use App\Models\EventBookmark;
use App\Models\User;
use App\Models\UserEventReaction;
use App\Services\Recommendation\RelatedEventFinder;

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
    $sameCategory = Event::factory()->create([
        'category' => EventCategory::Music,
        'tags' => [],
        'venue' => 'Alt Loc',
        'starts_at' => now()->addDays(4),
    ]);

    Event::factory()->create([
        'category' => EventCategory::Sports,
        'tags' => [],
        'venue' => 'Alt Loc',
        'starts_at' => now()->addDays(4),
    ]);

    $related = $this->finder->find($this->subject);

    expect($related->pluck('id')->all())->toBe([$sameCategory->id]);
});

it('ranks a shared-tag, same-venue event above a bare category match', function () {
    $bareCategory = Event::factory()->create([
        'category' => EventCategory::Music,
        'tags' => [],
        'venue' => 'Alt Loc',
        'city' => 'Timișoara',
        'starts_at' => now()->addDays(4),
    ]);

    $strongMatch = Event::factory()->create([
        'category' => EventCategory::Music,
        'tags' => ['jazz', 'live-music'],
        'venue' => 'Casa Tineretului',
        'city' => 'Timișoara',
        'starts_at' => now()->addDays(5),
    ]);

    $related = $this->finder->find($this->subject);

    expect($related->pluck('id')->all())->toBe([$strongMatch->id, $bareCategory->id]);
});

it('matches on a shared tag alone, across categories', function () {
    $taggedOnly = Event::factory()->create([
        'category' => EventCategory::Community,
        'tags' => ['jazz'],
        'venue' => 'Alt Loc',
        'starts_at' => now()->addDays(4),
    ]);

    $related = $this->finder->find($this->subject);

    expect($related->pluck('id')->all())->toContain($taggedOnly->id);
});

it('matches on a shared venue alone, across categories', function () {
    $sameVenue = Event::factory()->create([
        'category' => EventCategory::Business,
        'tags' => [],
        'venue' => 'Casa Tineretului',
        'starts_at' => now()->addDays(4),
    ]);

    $related = $this->finder->find($this->subject);

    expect($related->pluck('id')->all())->toContain($sameVenue->id);
});

it('never includes the subject event itself', function () {
    Event::factory()->create([
        'category' => EventCategory::Music,
        'tags' => ['jazz'],
        'starts_at' => now()->addDays(4),
    ]);

    $related = $this->finder->find($this->subject);

    expect($related->pluck('id')->all())->not->toContain($this->subject->id);
});

it('excludes hidden, merged and past events', function () {
    Event::factory()->create([
        'category' => EventCategory::Music,
        'tags' => ['jazz'],
        'starts_at' => now()->addDays(4),
        'is_hidden' => true,
    ]);

    // The survivor of the merge is itself unrelated, so only the merged
    // duplicate's exclusion is under test here.
    $canonical = Event::factory()->create([
        'category' => EventCategory::Sports,
        'tags' => [],
        'venue' => 'Alt Loc',
        'starts_at' => now()->addDays(6),
    ]);
    Event::factory()->merged($canonical)->create([
        'category' => EventCategory::Music,
        'tags' => ['jazz'],
        'starts_at' => now()->addDays(4),
    ]);

    Event::factory()->past()->create([
        'category' => EventCategory::Music,
        'tags' => ['jazz'],
    ]);

    expect($this->finder->find($this->subject))->toBeEmpty();
});

it('excludes events the user dismissed as not interested', function () {
    $user = User::factory()->create();

    $dismissed = Event::factory()->create([
        'category' => EventCategory::Music,
        'tags' => ['jazz'],
        'starts_at' => now()->addDays(4),
    ]);

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

    $candidate = Event::factory()->create([
        'category' => EventCategory::Music,
        'tags' => ['jazz'],
        'starts_at' => now()->addDays(4),
    ]);

    UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $candidate->id,
        'reaction' => Reaction::Interested,
    ]);
    EventBookmark::factory()->create([
        'user_id' => $user->id,
        'event_id' => $candidate->id,
    ]);

    $related = $this->finder->find($this->subject, $user);

    expect($related->first()->relationLoaded('reactions'))->toBeTrue()
        ->and($related->first()->relationLoaded('bookmarks'))->toBeTrue()
        ->and($related->first()->reactions->first()->reaction)->toBe(Reaction::Interested)
        ->and($related->first()->bookmarks)->toHaveCount(1);
});

it('returns nothing when no event shares any signal', function () {
    Event::factory()->create([
        'category' => EventCategory::Sports,
        'tags' => ['outdoor'],
        'venue' => 'Alt Loc',
        'starts_at' => now()->addDays(4),
    ]);

    expect($this->finder->find($this->subject))->toBeEmpty();
});

it('still scores when the points config is missing or partial', function () {
    $candidate = Event::factory()->create([
        'category' => EventCategory::Music,
        'tags' => ['jazz'],
        'starts_at' => now()->addDays(4),
    ]);

    // A config cache written before this key existed, and a partial override.
    foreach ([null, ['category' => 3]] as $points) {
        config(['eventpulse.recommendation.related.points' => $points]);

        expect($this->finder->find($this->subject)->pluck('id')->all())
            ->toBe([$candidate->id]);
    }
});

it('honours the configured result limit', function () {
    Event::factory()->count(5)->create([
        'category' => EventCategory::Music,
        'tags' => ['jazz'],
        'starts_at' => now()->addDays(4),
    ]);

    config(['eventpulse.recommendation.related.limit' => 2]);

    expect($this->finder->find($this->subject))->toHaveCount(2);
});
