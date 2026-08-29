<?php

declare(strict_types=1);

use App\Enums\EventCategory;
use App\Enums\Reaction;
use App\Models\DiscoveryLog;
use App\Models\Event;
use App\Models\EventBookmark;
use App\Models\EventSource;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserEventReaction;
use App\Services\InterestProfile\ProfileUpdater;
use App\Services\Recommendation\DiscoveryEngine;
use App\Services\Recommendation\FeedbackProcessor;

beforeEach(function () {
    $this->processor = new FeedbackProcessor(
        profileUpdater: new ProfileUpdater,
        discoveryEngine: new DiscoveryEngine,
    );
});

it('processes an interested reaction and increases category score', function () {
    $user = User::factory()->create(['interest_profile' => ['music' => 0.5]]);
    $event = Event::factory()->create(['category' => EventCategory::Music, 'tags' => ['jazz']]);

    $reaction = UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'reaction' => Reaction::Interested,
        'is_processed' => false,
    ]);

    $this->processor->processReaction($reaction);

    $reaction->refresh();
    $user->refresh();

    expect($reaction->is_processed)->toBeTrue();
    expect($user->interest_profile['music'])->toBeGreaterThan(0.5);
});

it('processes not_interested and decreases score', function () {
    $user = User::factory()->create(['interest_profile' => ['sports' => 0.6]]);
    $event = Event::factory()->create(['category' => EventCategory::Sports, 'tags' => []]);

    $reaction = UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'reaction' => Reaction::NotInterested,
        'is_processed' => false,
    ]);

    $this->processor->processReaction($reaction);

    $user->refresh();
    expect($user->interest_profile['sports'])->toBeLessThan(0.6);
});

it('skips already processed reactions', function () {
    $user = User::factory()->create(['interest_profile' => ['music' => 0.5]]);
    $event = Event::factory()->create(['category' => EventCategory::Music, 'tags' => []]);

    $reaction = UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'reaction' => Reaction::Interested,
        'is_processed' => true,
    ]);

    $this->processor->processReaction($reaction);

    $user->refresh();
    expect($user->interest_profile['music'])->toBe(0.5);
});

it('processes all unprocessed reactions in batch', function () {
    $user = User::factory()->create(['interest_profile' => ['music' => 0.5, 'sports' => 0.5]]);

    $event1 = Event::factory()->create(['category' => EventCategory::Music, 'tags' => []]);
    $event2 = Event::factory()->create(['category' => EventCategory::Sports, 'tags' => []]);

    UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $event1->id,
        'reaction' => Reaction::Interested,
        'is_processed' => false,
    ]);
    UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $event2->id,
        'reaction' => Reaction::NotInterested,
        'is_processed' => false,
    ]);

    $count = $this->processor->processUnprocessed();

    expect($count)->toBe(2);
    expect(UserEventReaction::where('is_processed', false)->count())->toBe(0);
});

it('a bookmark applies the saved delta and records its ledger', function () {
    $user = User::factory()->create(['interest_profile' => ['arts' => 0.4]]);
    $event = Event::factory()->create(['category' => EventCategory::Arts, 'tags' => []]);

    $bookmark = EventBookmark::factory()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'is_processed' => false,
    ]);

    $this->processor->processBookmark($bookmark);

    $user->refresh();
    $bookmark->refresh();
    $savedDelta = config('eventpulse.feedback.deltas.saved.category');

    expect($user->interest_profile['arts'])->toEqualWithDelta(0.4 + $savedDelta, 0.0001)
        ->and($bookmark->is_processed)->toBeTrue()
        ->and($bookmark->applied_deltas['arts'])->toEqualWithDelta($savedDelta, 0.0001);
});

it('a bookmark stacks with an interested reaction on the same event', function () {
    $user = User::factory()->create(['interest_profile' => []]);
    $event = Event::factory()->create(['category' => EventCategory::Arts, 'tags' => []]);

    $reaction = UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'reaction' => Reaction::Interested,
        'is_processed' => false,
    ]);
    $bookmark = EventBookmark::factory()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'is_processed' => false,
    ]);

    $this->processor->processReaction($reaction);
    $this->processor->processBookmark($bookmark);

    $expected = config('eventpulse.feedback.deltas.interested.category')
        + config('eventpulse.feedback.deltas.saved.category');

    expect($user->fresh()->interest_profile['arts'])->toEqualWithDelta($expected, 0.0001);
});

it('re-scores from scratch when the reaction changes', function () {
    $user = User::factory()->create(['interest_profile' => []]);
    $event = Event::factory()->create(['category' => EventCategory::Arts, 'tags' => []]);

    $reaction = UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'reaction' => Reaction::Interested,
        'is_processed' => false,
    ]);

    $this->processor->processReaction($reaction);

    // Change of mind: the old delta must be reversed, not stacked on.
    // refresh() first because the processor writes to its own freshly-locked
    // instance, exactly as ReactionRecorder::record() re-reads before writing.
    $reaction->refresh();
    $reaction->forceFill(['reaction' => Reaction::NotInterested, 'is_processed' => false])->save();
    $this->processor->processReaction($reaction);

    $notInterested = config('eventpulse.feedback.deltas.not_interested.category');

    expect($user->fresh()->interest_profile['arts'])
        ->toEqualWithDelta(max(0.0, $notInterested), 0.0001);
});

it('reversing a signal undoes exactly its contribution', function () {
    $user = User::factory()->create(['interest_profile' => ['arts' => 0.3]]);
    $event = Event::factory()->create(['category' => EventCategory::Arts, 'tags' => []]);

    $reaction = UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'reaction' => Reaction::Interested,
        'is_processed' => false,
    ]);

    $this->processor->processReaction($reaction);
    $reaction->refresh();

    $this->processor->reverseSignal($user, $event->id, $reaction->applied_deltas ?? []);

    expect($user->fresh()->interest_profile['arts'])->toEqualWithDelta(0.3, 0.0001);
});

it('reversing a legacy row with no ledger is a no-op', function () {
    $user = User::factory()->create(['interest_profile' => ['arts' => 0.3]]);
    $event = Event::factory()->create(['category' => EventCategory::Arts, 'tags' => []]);

    $this->processor->reverseSignal($user, $event->id, []);

    expect($user->fresh()->interest_profile['arts'])->toEqualWithDelta(0.3, 0.0001);
});

it('applies exploration reward and records outcome for a discovery reaction', function () {
    $user = User::factory()->create(['interest_profile' => ['music' => 0.2]]);
    $event = Event::factory()->create(['category' => EventCategory::Music, 'tags' => []]);

    $log = DiscoveryLog::create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'category_explored' => 'music',
        'surprise_score' => 0.8,
    ]);

    $reaction = UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'reaction' => Reaction::Interested,
        'is_processed' => false,
    ]);

    $this->processor->processReaction($reaction);

    $user->refresh();
    $log->refresh();

    // interested category delta 0.15 * reward_multiplier 1.5 = 0.225 → 0.2 + 0.225
    expect($user->interest_profile['music'])->toEqualWithDelta(0.425, 0.0001)
        ->and($log->outcome)->toBe('interested');
});

it('applies passive decay to ignored events in old notifications', function () {
    $user = User::factory()->create(['interest_profile' => ['music' => 0.5, 'sports' => 0.5]]);

    $ignored = Event::factory()->create(['category' => EventCategory::Music, 'tags' => []]);
    $reacted = Event::factory()->create(['category' => EventCategory::Sports, 'tags' => []]);

    UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $reacted->id,
        'reaction' => Reaction::Interested,
    ]);

    $notification = Notification::factory()->create([
        'user_id' => $user->id,
        'event_ids' => [$ignored->id, $reacted->id],
        'discovery_event_ids' => [],
        'sent_at' => now()->subDays(4),
    ]);

    $count = $this->processor->applyPassiveDecay();

    $user->refresh();
    $notification->refresh();

    expect($count)->toBe(1);
    expect($user->interest_profile['music'])->toEqualWithDelta(0.48, 0.0001);
    expect($user->interest_profile['sports'])->toBe(0.5);
    expect($notification->decay_applied_at)->not->toBeNull();
});

it('applies the source penalty to ignored events', function () {
    $user = User::factory()->create([
        'interest_profile' => ['music' => 0.5, 'source:iabilet' => 0.4],
    ]);

    $ignored = Event::factory()->create([
        'category' => EventCategory::Music,
        'source' => 'iabilet',
        'tags' => [],
    ]);

    Notification::factory()->create([
        'user_id' => $user->id,
        'event_ids' => [$ignored->id],
        'discovery_event_ids' => [],
        'sent_at' => now()->subDays(4),
    ]);

    $this->processor->applyPassiveDecay();

    expect($user->fresh()->interest_profile['source:iabilet'])->toEqualWithDelta(0.39, 0.0001);
});

it('reacting to an event moves the score of every provider that reported it', function () {
    $user = User::factory()->create(['interest_profile' => []]);
    $event = Event::factory()->create([
        'category' => EventCategory::Music,
        'source' => 'iabilet',
        'tags' => [],
    ]);

    EventSource::factory()->forSource('iabilet')->create(['event_id' => $event->id]);
    EventSource::factory()->forSource('allevents')->create(['event_id' => $event->id]);

    $reaction = UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'reaction' => Reaction::Interested,
        'is_processed' => false,
    ]);

    $this->processor->processReaction($reaction);

    $profile = $user->fresh()->interest_profile;
    expect($profile['source:iabilet'])->toEqualWithDelta(0.05, 0.0001)
        ->and($profile['source:allevents'])->toEqualWithDelta(0.05, 0.0001);

    // The ledger must carry the source keys, or removing the reaction strands them.
    expect($reaction->fresh()->applied_deltas)
        ->toHaveKeys(['source:iabilet', 'source:allevents']);
});

it('removing a reaction reverses the source score it applied', function () {
    $user = User::factory()->create(['interest_profile' => ['source:iabilet' => 0.3]]);
    $event = Event::factory()->create([
        'category' => EventCategory::Music,
        'source' => 'iabilet',
        'tags' => [],
    ]);

    $reaction = UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'reaction' => Reaction::Interested,
        'is_processed' => false,
    ]);

    $this->processor->processReaction($reaction);
    $applied = $reaction->fresh()->applied_deltas;
    $this->processor->reverseSignal($user->fresh(), $event->id, $applied);

    expect($user->fresh()->interest_profile['source:iabilet'])->toEqualWithDelta(0.3, 0.0001);
});

it('does not decay notifications still inside the ignore window', function () {
    $user = User::factory()->create(['interest_profile' => ['music' => 0.5]]);
    $event = Event::factory()->create(['category' => EventCategory::Music, 'tags' => []]);

    Notification::factory()->create([
        'user_id' => $user->id,
        'event_ids' => [$event->id],
        'discovery_event_ids' => [],
        'sent_at' => now()->subHours(1),
    ]);

    $count = $this->processor->applyPassiveDecay();

    $user->refresh();
    expect($count)->toBe(0);
    expect($user->interest_profile['music'])->toBe(0.5);
});

it('does not decay the same notification twice', function () {
    $user = User::factory()->create(['interest_profile' => ['music' => 0.5]]);
    $event = Event::factory()->create(['category' => EventCategory::Music, 'tags' => []]);

    Notification::factory()->create([
        'user_id' => $user->id,
        'event_ids' => [$event->id],
        'discovery_event_ids' => [],
        'sent_at' => now()->subDays(4),
    ]);

    $this->processor->applyPassiveDecay();
    $secondCount = $this->processor->applyPassiveDecay();

    $user->refresh();
    expect($secondCount)->toBe(0);
    expect($user->interest_profile['music'])->toEqualWithDelta(0.48, 0.0001);
});

it('keeps the discovery outcome when one of two signals is removed', function () {
    // Reactions and bookmarks are independent but share discovery_logs.outcome.
    // Last-writer-wins would let un-saving erase the outcome that a still-present
    // "interested" earned, silently suppressing that category from discovery.
    $user = User::factory()->create(['interest_profile' => []]);
    $event = Event::factory()->create(['category' => EventCategory::Music, 'tags' => []]);

    $log = DiscoveryLog::create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'category_explored' => 'music',
        'surprise_score' => 0.8,
    ]);

    $reaction = UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'reaction' => Reaction::Interested,
        'is_processed' => false,
    ]);
    $bookmark = EventBookmark::factory()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'is_processed' => false,
    ]);

    $this->processor->processReaction($reaction);
    $this->processor->processBookmark($bookmark);

    expect($log->fresh()->outcome)->toBe('saved');

    // Un-save, keeping the reaction: the outcome must fall back to it, not null.
    $bookmark->refresh();
    $applied = $bookmark->applied_deltas ?? [];
    $bookmark->delete();
    $this->processor->reverseSignal($user, $event->id, $applied);

    expect($log->fresh()->outcome)->toBe('interested');
});

it('clears the discovery outcome only when no signal remains', function () {
    $user = User::factory()->create(['interest_profile' => []]);
    $event = Event::factory()->create(['category' => EventCategory::Music, 'tags' => []]);

    $log = DiscoveryLog::create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'category_explored' => 'music',
        'surprise_score' => 0.8,
    ]);

    $reaction = UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'reaction' => Reaction::Interested,
        'is_processed' => false,
    ]);

    $this->processor->processReaction($reaction);
    $reaction->refresh();
    $applied = $reaction->applied_deltas ?? [];
    $reaction->delete();

    $this->processor->reverseSignal($user, $event->id, $applied);

    expect($log->fresh()->outcome)->toBeNull();
});

it('does not let a negative reaction overwrite a bookmark as the outcome', function () {
    $user = User::factory()->create(['interest_profile' => []]);
    $event = Event::factory()->create(['category' => EventCategory::Music, 'tags' => []]);

    $log = DiscoveryLog::create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'category_explored' => 'music',
        'surprise_score' => 0.8,
    ]);

    $bookmark = EventBookmark::factory()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'is_processed' => false,
    ]);
    $this->processor->processBookmark($bookmark);

    $reaction = UserEventReaction::factory()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'reaction' => Reaction::NotInterested,
        'is_processed' => false,
    ]);
    $this->processor->processReaction($reaction);

    // The bookmark still stands, so the exploration is still a hit.
    expect($log->fresh()->outcome)->toBe('saved');
});
