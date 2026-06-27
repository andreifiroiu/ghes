<?php

declare(strict_types=1);

use App\DTOs\RecommendationBatch;
use App\Enums\EventCategory;
use App\Enums\Reaction;
use App\Models\Event;
use App\Models\User;
use App\Models\UserEventReaction;
use App\Services\InterestProfile\ProfileScorer;
use App\Services\Recommendation\DiscoveryEngine;
use App\Services\Recommendation\DiversityFilter;
use App\Services\Recommendation\RecommendationEngine;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->engine = new RecommendationEngine(
        profileScorer: new ProfileScorer,
        discoveryEngine: new DiscoveryEngine,
        diversityFilter: new DiversityFilter,
    );
});

// ---------------------------------------------------------------
// scoreEvent – individual factor tests
// ---------------------------------------------------------------

it('scores music events higher for users who like music', function () {
    $user = User::factory()->create([
        'interest_profile' => ['music' => 0.9, 'sports' => 0.1],
        'city' => 'Bucharest',
    ]);

    $musicEvent = Event::factory()->create([
        'category' => EventCategory::Music,
        'tags' => ['jazz'],
        'city' => 'Bucharest',
        'starts_at' => now()->addDays(3),
        'is_classified' => true,
    ]);

    $sportsEvent = Event::factory()->create([
        'category' => EventCategory::Sports,
        'tags' => ['football'],
        'city' => 'Bucharest',
        'starts_at' => now()->addDays(3),
        'is_classified' => true,
    ]);

    expect($this->engine->scoreEvent($user, $musicEvent))
        ->toBeGreaterThan($this->engine->scoreEvent($user, $sportsEvent));
});

it('always returns a score between 0 and 1', function () {
    $user = User::factory()->create([
        'interest_profile' => ['music' => 1.0],
        'city' => 'Bucharest',
    ]);

    $event = Event::factory()->create([
        'category' => EventCategory::Music,
        'city' => 'Bucharest',
        'starts_at' => now()->addDays(2),
        'is_free' => true,
        'popularity_score' => 100,
    ]);

    $score = $this->engine->scoreEvent($user, $event);

    expect($score)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0);
});

// -- categoryMatch ---------------------------------------------------

it('category match returns profile score for the category', function () {
    $user = User::factory()->create(['interest_profile' => ['arts' => 0.75]]);
    $event = Event::factory()->create(['category' => EventCategory::Arts]);

    expect($this->engine->categoryMatch($user, $event))->toBe(0.75);
});

it('category match returns 0 for unknown categories', function () {
    $user = User::factory()->create(['interest_profile' => ['music' => 0.8]]);
    $event = Event::factory()->create(['category' => EventCategory::Technology]);

    expect($this->engine->categoryMatch($user, $event))->toBe(0.0);
});

// -- tagMatch --------------------------------------------------------

it('tag match averages profile tag scores', function () {
    $user = User::factory()->create([
        'interest_profile' => ['tag:jazz' => 0.8, 'tag:outdoor' => 0.4],
    ]);
    $event = Event::factory()->create(['tags' => ['jazz', 'outdoor']]);

    expect($this->engine->tagMatch($user, $event))->toEqualWithDelta(0.6, 0.0001);
});

it('tag match returns 0 when event has no tags', function () {
    $user = User::factory()->create(['interest_profile' => ['tag:jazz' => 0.9]]);
    $event = Event::factory()->create(['tags' => []]);

    expect($this->engine->tagMatch($user, $event))->toBe(0.0);
});

// -- locationMatch ---------------------------------------------------

it('location match returns 1.0 for same city', function () {
    $user = User::factory()->create(['city' => 'Bucharest']);
    $event = Event::factory()->create(['city' => 'Bucharest']);

    expect($this->engine->locationMatch($user, $event))->toBe(1.0);
});

it('location match is case-insensitive', function () {
    $user = User::factory()->create(['city' => 'bucharest']);
    $event = Event::factory()->create(['city' => 'Bucharest']);

    expect($this->engine->locationMatch($user, $event))->toBe(1.0);
});

it('location match returns 0.3 for different city', function () {
    $user = User::factory()->create(['city' => 'Bucharest']);
    $event = Event::factory()->create(['city' => 'Cluj-Napoca']);

    expect($this->engine->locationMatch($user, $event))->toBe(0.3);
});

it('location match returns 0.5 when user has no city', function () {
    $user = User::factory()->create(['city' => null]);
    $event = Event::factory()->create(['city' => 'Bucharest']);

    expect($this->engine->locationMatch($user, $event))->toBe(0.5);
});

// -- timeMatch -------------------------------------------------------

it('time match scores near-future events highest', function () {
    $event1day = Event::factory()->create(['starts_at' => now()->addDay()]);
    $event14days = Event::factory()->create(['starts_at' => now()->addDays(14)]);

    expect($this->engine->timeMatch($event1day))
        ->toBeGreaterThan($this->engine->timeMatch($event14days));
});

it('time match returns 0 for past events', function () {
    $event = Event::factory()->create(['starts_at' => now()->subDay()]);

    expect($this->engine->timeMatch($event))->toBe(0.0);
});

it('time match returns 0 when no start date', function () {
    $event = Event::factory()->create(['starts_at' => null]);

    expect($this->engine->timeMatch($event))->toBe(0.0);
});

// -- priceMatch ------------------------------------------------------

it('price match returns 1.0 for free events', function () {
    $event = Event::factory()->create(['is_free' => true, 'price_min' => null]);

    expect($this->engine->priceMatch($event))->toBe(1.0);
});

it('price match decays for expensive events', function () {
    $cheapEvent = Event::factory()->create(['is_free' => false, 'price_min' => 20.0]);
    $expensiveEvent = Event::factory()->create(['is_free' => false, 'price_min' => 180.0]);

    expect($this->engine->priceMatch($cheapEvent))
        ->toBeGreaterThan($this->engine->priceMatch($expensiveEvent));
});

it('price match floors at 0.2', function () {
    $event = Event::factory()->create(['is_free' => false, 'price_min' => 999.0]);

    expect($this->engine->priceMatch($event))->toBe(0.2);
});

// -- freshnessBonus --------------------------------------------------

it('freshness bonus decays over time', function () {
    $fresh = Event::factory()->create();
    $stale = Event::factory()->create();

    // Bypass Eloquent timestamps to set created_at directly
    DB::table('events')
        ->where('id', $stale->id)
        ->update(['created_at' => now()->subDays(20)]);
    $stale = Event::find($stale->id);

    $freshScore = $this->engine->freshnessBonus($fresh);
    $staleScore = $this->engine->freshnessBonus($stale);

    // Fresh event scraped today should score ~1.0
    expect($freshScore)->toEqualWithDelta(1.0, 0.01);
    // Event scraped 20 days ago should score significantly lower
    expect($staleScore)->toBeLessThan(0.5);
    expect($freshScore)->toBeGreaterThan($staleScore);
});

// -- popularitySignal ------------------------------------------------

it('popularity signal normalises to 0-1 range', function () {
    $popular = Event::factory()->create(['popularity_score' => 80]);
    $unpopular = Event::factory()->create(['popularity_score' => 10]);

    expect($this->engine->popularitySignal($popular))->toBe(0.8);
    expect($this->engine->popularitySignal($unpopular))->toBe(0.1);
});

it('popularity signal caps at 1.0', function () {
    $event = Event::factory()->create(['popularity_score' => 200]);

    expect($this->engine->popularitySignal($event))->toBe(1.0);
});

// ---------------------------------------------------------------
// recommend()
// ---------------------------------------------------------------

it('returns a RecommendationBatch with events', function () {
    $user = User::factory()->create([
        'interest_profile' => ['music' => 0.8, 'arts' => 0.6],
        'city' => 'Bucharest',
    ]);

    Event::factory()->count(12)->create([
        'city' => 'Bucharest',
        'starts_at' => now()->addDays(5),
        'is_classified' => true,
    ]);

    $batch = $this->engine->recommend($user, 8);

    expect($batch)->toBeInstanceOf(RecommendationBatch::class);
    expect($batch->userId)->toBe($user->id);
    expect($batch->recommendedEventIds)->toBeArray();
    expect($batch->discoveryEventIds)->toBeArray();
    expect(count($batch->recommendedEventIds) + count($batch->discoveryEventIds))
        ->toBeLessThanOrEqual(8);
    expect($batch->generatedAt)->toBeInstanceOf(DateTimeImmutable::class);
});

it('excludes events the user already reacted to', function () {
    $user = User::factory()->create([
        'interest_profile' => ['music' => 0.9],
        'city' => 'Bucharest',
    ]);

    $reactedEvent = Event::factory()->create([
        'category' => EventCategory::Music,
        'city' => 'Bucharest',
        'starts_at' => now()->addDays(3),
        'is_classified' => true,
    ]);

    UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $reactedEvent->id,
        'reaction' => Reaction::Interested,
    ]);

    $freshEvent = Event::factory()->create([
        'category' => EventCategory::Music,
        'city' => 'Bucharest',
        'starts_at' => now()->addDays(3),
        'is_classified' => true,
    ]);

    $batch = $this->engine->recommend($user, 8);

    expect($batch->recommendedEventIds)->not->toContain($reactedEvent->id);
});

it('ranks higher-scored events first in recommended list', function () {
    $user = User::factory()->create([
        'interest_profile' => ['music' => 0.95, 'technology' => 0.05],
        'city' => 'Bucharest',
    ]);

    $musicEvent = Event::factory()->create([
        'category' => EventCategory::Music,
        'tags' => [],
        'city' => 'Bucharest',
        'starts_at' => now()->addDays(2),
        'is_classified' => true,
        'is_free' => true,
        'popularity_score' => 80,
    ]);

    $techEvent = Event::factory()->create([
        'category' => EventCategory::Technology,
        'tags' => [],
        'city' => 'Bucharest',
        'starts_at' => now()->addDays(2),
        'is_classified' => true,
        'is_free' => false,
        'price_min' => 100.0,
        'popularity_score' => 10,
    ]);

    $batch = $this->engine->recommend($user, 8);

    // Music event should appear before tech in recommendations
    $musicPos = array_search($musicEvent->id, $batch->recommendedEventIds);
    $techPos = array_search($techEvent->id, $batch->recommendedEventIds);

    if ($musicPos !== false && $techPos !== false) {
        expect($musicPos)->toBeLessThan($techPos);
    }
});

it('includes discovery events in the batch', function () {
    $user = User::factory()->create([
        'interest_profile' => ['music' => 0.95],
        'city' => 'Bucharest',
    ]);

    // Create events in categories the user doesn't like (discovery candidates)
    Event::factory()->count(3)->create([
        'category' => EventCategory::Technology,
        'city' => 'Bucharest',
        'starts_at' => now()->addDays(5),
        'is_classified' => true,
    ]);

    // Create events in the user's preferred category
    Event::factory()->count(8)->create([
        'category' => EventCategory::Music,
        'city' => 'Bucharest',
        'starts_at' => now()->addDays(5),
        'is_classified' => true,
    ]);

    $batch = $this->engine->recommend($user, 8);

    expect($batch->discoveryEventIds)->not->toBeEmpty();
});

it('excludes events carrying the user negative tags', function () {
    $user = User::factory()->create([
        'interest_profile' => ['music' => 0.9, 'negtag:techno' => 1.0],
        'city' => 'Bucharest',
    ]);

    $blocked = Event::factory()->create([
        'category' => EventCategory::Music,
        'tags' => ['techno'],
        'city' => 'Bucharest',
        'starts_at' => now()->addDays(3),
        'is_classified' => true,
    ]);

    $allowed = Event::factory()->create([
        'category' => EventCategory::Music,
        'tags' => ['jazz'],
        'city' => 'Bucharest',
        'starts_at' => now()->addDays(3),
        'is_classified' => true,
    ]);

    $batch = $this->engine->recommend($user, 8);

    expect($batch->recommendedEventIds)->not->toContain($blocked->id);
    expect($batch->recommendedEventIds)->toContain($allowed->id);
});

it('handles empty candidate pool gracefully', function () {
    $user = User::factory()->create([
        'interest_profile' => ['music' => 0.8],
        'city' => 'NonexistentCity',
    ]);

    $batch = $this->engine->recommend($user, 8);

    expect($batch->recommendedEventIds)->toBeEmpty();
    expect($batch->totalScore)->toBe(0.0);
});

it('combines multiple scoring factors for realistic ranking', function () {
    $user = User::factory()->create([
        'interest_profile' => [
            'music' => 0.7,
            'food' => 0.6,
            'tag:jazz' => 0.8,
            'tag:street-food' => 0.5,
        ],
        'city' => 'Bucharest',
    ]);

    // Perfect match: right category, right city, right tags, free, fresh, popular
    $perfect = Event::factory()->create([
        'category' => EventCategory::Music,
        'tags' => ['jazz'],
        'city' => 'Bucharest',
        'starts_at' => now()->addDays(2),
        'is_classified' => true,
        'is_free' => true,
        'popularity_score' => 90,
    ]);

    // Mediocre: wrong city, expensive, old, unpopular
    $mediocre = Event::factory()->create([
        'category' => EventCategory::Food,
        'tags' => [],
        'city' => 'Timisoara',
        'starts_at' => now()->addDays(20),
        'is_classified' => true,
        'is_free' => false,
        'price_min' => 150.0,
        'popularity_score' => 5,
        'created_at' => now()->subDays(25),
    ]);

    $perfectScore = $this->engine->scoreEvent($user, $perfect);
    $mediocreScore = $this->engine->scoreEvent($user, $mediocre);

    expect($perfectScore)->toBeGreaterThan($mediocreScore);
    expect($perfectScore)->toBeGreaterThan(0.5);
    expect($mediocreScore)->toBeLessThan(0.4);
});
