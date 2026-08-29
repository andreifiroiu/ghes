<?php

declare(strict_types=1);

use App\Enums\EventCategory;
use App\Models\Event;
use App\Models\EventSource;
use App\Models\User;
use App\Services\InterestProfile\ProfileUpdater;

beforeEach(function () {
    $this->updater = new ProfileUpdater;
});

it('increases category score for interested reaction', function () {
    $user = User::factory()->create([
        'interest_profile' => ['music' => 0.5],
    ]);

    $event = Event::factory()->create([
        'category' => EventCategory::Music,
        'tags' => ['jazz'],
    ]);

    $this->updater->updateFromFeedback($user, $event, 'interested');

    $user->refresh();
    expect($user->interest_profile['music'])->toBeGreaterThan(0.5);
});

it('decreases category score for not_interested reaction', function () {
    $user = User::factory()->create([
        'interest_profile' => ['sports' => 0.6],
    ]);

    $event = Event::factory()->create([
        'category' => EventCategory::Sports,
        'tags' => [],
    ]);

    $this->updater->updateFromFeedback($user, $event, 'not_interested');

    $user->refresh();
    expect($user->interest_profile['sports'])->toBeLessThan(0.6);
});

it('clamps score to maximum 1.0', function () {
    $user = User::factory()->create([
        'interest_profile' => ['music' => 0.95],
    ]);

    $event = Event::factory()->create([
        'category' => EventCategory::Music,
        'tags' => [],
    ]);

    $this->updater->updateFromFeedback($user, $event, 'saved');

    $user->refresh();
    expect($user->interest_profile['music'])->toBeLessThanOrEqual(1.0);
});

it('clamps score to minimum 0.0', function () {
    $user = User::factory()->create([
        'interest_profile' => ['technology' => 0.05],
    ]);

    $event = Event::factory()->create([
        'category' => EventCategory::Technology,
        'tags' => [],
    ]);

    $this->updater->updateFromFeedback($user, $event, 'not_interested');

    $user->refresh();
    expect($user->interest_profile['technology'])->toBeGreaterThanOrEqual(0.0);
});

it('updates tag scores alongside category scores', function () {
    $user = User::factory()->create([
        'interest_profile' => ['music' => 0.5, 'tag:jazz' => 0.3],
    ]);

    $event = Event::factory()->create([
        'category' => EventCategory::Music,
        'tags' => ['jazz', 'live-music'],
    ]);

    $this->updater->updateFromFeedback($user, $event, 'interested');

    $user->refresh();
    expect($user->interest_profile)->toHaveKey('tag:jazz');
    expect($user->interest_profile)->toHaveKey('tag:live-music');
    expect($user->interest_profile['tag:jazz'])->toBeGreaterThan(0.3);
});

it('does nothing for zero delta reactions', function () {
    $user = User::factory()->create([
        'interest_profile' => ['music' => 0.5],
    ]);

    $event = Event::factory()->create([
        'category' => EventCategory::Music,
        'tags' => [],
    ]);

    $this->updater->updateFromFeedback($user, $event, 'unknown_reaction');

    $user->refresh();
    expect($user->interest_profile['music'])->toBe(0.5);
});

it('correctly clamps scores via clampScore method', function () {
    expect($this->updater->clampScore(1.5))->toBe(1.0);
    expect($this->updater->clampScore(-0.3))->toBe(0.0);
    expect($this->updater->clampScore(0.7))->toBe(0.7);
});

it('applies distinct category and tag deltas for interested', function () {
    $user = User::factory()->create(['interest_profile' => []]);
    $event = Event::factory()->create([
        'category' => EventCategory::Music,
        'tags' => ['jazz'],
    ]);

    $this->updater->updateFromFeedback($user, $event, 'interested');

    $user->refresh();
    expect($user->interest_profile['music'])->toEqualWithDelta(0.15, 0.0001);
    expect($user->interest_profile['tag:jazz'])->toEqualWithDelta(0.20, 0.0001);
});

it('returns the effective delta actually applied, not the nominal one', function () {
    // 0.95 + 0.20 clamps to 1.0, so only 0.05 was really applied. Reversal has
    // to undo 0.05 or the profile drifts.
    $user = User::factory()->create(['interest_profile' => ['music' => 0.95]]);
    $event = Event::factory()->create([
        'category' => EventCategory::Music,
        'tags' => [],
    ]);

    $applied = $this->updater->updateFromFeedback($user, $event, 'saved');

    expect($applied['music'])->toEqualWithDelta(0.05, 0.0001);
    expect($user->fresh()->interest_profile['music'])->toEqualWithDelta(1.0, 0.0001);
});

it('reverts exactly what was applied, even at a clamp boundary', function () {
    $user = User::factory()->create(['interest_profile' => ['music' => 0.95]]);
    $event = Event::factory()->create([
        'category' => EventCategory::Music,
        'tags' => ['jazz'],
    ]);

    $applied = $this->updater->updateFromFeedback($user, $event, 'saved');
    $this->updater->revert($user, $applied);

    $user->refresh();
    expect($user->interest_profile['music'])->toEqualWithDelta(0.95, 0.0001);
    expect($user->interest_profile['tag:jazz'])->toEqualWithDelta(0.0, 0.0001);
});

it('reverting an empty ledger leaves the profile untouched', function () {
    $user = User::factory()->create(['interest_profile' => ['music' => 0.4]]);

    $this->updater->revert($user, []);

    expect($user->fresh()->interest_profile['music'])->toEqualWithDelta(0.4, 0.0001);
});

it('applies a small negative category decay for ignored events', function () {
    $user = User::factory()->create(['interest_profile' => ['music' => 0.5]]);
    $event = Event::factory()->create(['category' => EventCategory::Music, 'tags' => []]);

    $this->updater->updateFromFeedback($user, $event, 'ignored');

    $user->refresh();
    expect($user->interest_profile['music'])->toEqualWithDelta(0.48, 0.0001);
});

it('raises the source score for a positive reaction', function () {
    $user = User::factory()->create(['interest_profile' => []]);
    $event = Event::factory()->create([
        'category' => EventCategory::Music,
        'source' => 'iabilet',
        'tags' => [],
    ]);

    $this->updater->updateFromFeedback($user, $event, 'interested');

    expect($user->fresh()->interest_profile['source:iabilet'])->toEqualWithDelta(0.05, 0.0001);
});

it('lowers the source score for a negative reaction', function () {
    $user = User::factory()->create(['interest_profile' => ['source:allevents' => 0.4]]);
    $event = Event::factory()->create([
        'category' => EventCategory::Sports,
        'source' => 'allevents',
        'tags' => [],
    ]);

    $this->updater->updateFromFeedback($user, $event, 'not_interested');

    expect($user->fresh()->interest_profile['source:allevents'])->toEqualWithDelta(0.35, 0.0001);
});

it('credits every provider that reported a deduplicated event', function () {
    $user = User::factory()->create(['interest_profile' => []]);
    $event = Event::factory()->create([
        'category' => EventCategory::Music,
        'source' => 'iabilet',
        'tags' => [],
    ]);

    EventSource::factory()->forSource('iabilet')->create(['event_id' => $event->id]);
    EventSource::factory()->forSource('zilesinopti')->create(['event_id' => $event->id]);

    $this->updater->updateFromFeedback($user, $event, 'saved');

    $profile = $user->fresh()->interest_profile;
    expect($profile['source:iabilet'])->toEqualWithDelta(0.07, 0.0001);
    expect($profile['source:zilesinopti'])->toEqualWithDelta(0.07, 0.0001);
});

it('amplifies the source delta for discovery events', function () {
    $user = User::factory()->create(['interest_profile' => []]);
    $event = Event::factory()->create([
        'category' => EventCategory::Music,
        'source' => 'meetup',
        'tags' => [],
    ]);

    $this->updater->updateFromFeedback($user, $event, 'interested', isDiscovery: true);

    // 0.05 * discovery.reward_multiplier (1.5)
    expect($user->fresh()->interest_profile['source:meetup'])->toEqualWithDelta(0.075, 0.0001);
});

it('reverts the source delta along with the rest of the ledger', function () {
    $user = User::factory()->create(['interest_profile' => ['source:iabilet' => 0.3]]);
    $event = Event::factory()->create([
        'category' => EventCategory::Music,
        'source' => 'iabilet',
        'tags' => [],
    ]);

    $applied = $this->updater->updateFromFeedback($user, $event, 'interested');
    $this->updater->revert($user, $applied);

    expect($user->fresh()->interest_profile['source:iabilet'])->toEqualWithDelta(0.3, 0.0001);
});
