<?php

declare(strict_types=1);

use App\Enums\Reaction;
use App\Models\DiscoveryLog;
use App\Models\Event;
use App\Models\EventSource;
use App\Models\User;
use App\Models\UserEventReaction;
use App\Services\Processing\EventMerger;

beforeEach(function () {
    $this->merger = app(EventMerger::class);
});

it('moves attached sources onto the canonical event', function () {
    $canonical = Event::factory()->create();
    $duplicate = Event::factory()->create();

    EventSource::factory()->count(2)->create(['event_id' => $duplicate->id]);

    $this->merger->mergeInto($canonical, $duplicate);

    expect(EventSource::where('event_id', $canonical->id)->count())->toBe(2)
        ->and(EventSource::where('event_id', $duplicate->id)->count())->toBe(0)
        ->and($canonical->fresh()->sources_count)->toBe(2);
});

it('marks the duplicate as merged instead of deleting it', function () {
    $canonical = Event::factory()->create();
    $duplicate = Event::factory()->create();

    $this->merger->mergeInto($canonical, $duplicate);

    expect(Event::find($duplicate->id))->not->toBeNull()
        ->and($duplicate->fresh()->merged_into_id)->toBe($canonical->id);
});

it('moves a reaction to the canonical event when the user had not reacted there', function () {
    $user = User::factory()->create();
    $canonical = Event::factory()->create();
    $duplicate = Event::factory()->create();

    $reaction = UserEventReaction::create([
        'user_id' => $user->id,
        'event_id' => $duplicate->id,
        'reaction' => Reaction::Interested,
        'is_processed' => true,
    ]);

    $this->merger->mergeInto($canonical, $duplicate);

    expect($reaction->fresh()->event_id)->toBe($canonical->id)
        ->and($reaction->fresh()->is_processed)->toBeTrue();
});

it('drops the duplicate reaction when the user already reacted to the canonical event', function () {
    $user = User::factory()->create();
    $canonical = Event::factory()->create();
    $duplicate = Event::factory()->create();

    $kept = UserEventReaction::create([
        'user_id' => $user->id,
        'event_id' => $canonical->id,
        'reaction' => Reaction::Interested,
        'is_processed' => true,
    ]);

    $dropped = UserEventReaction::create([
        'user_id' => $user->id,
        'event_id' => $duplicate->id,
        'reaction' => Reaction::NotInterested,
        'is_processed' => true,
    ]);

    $this->merger->mergeInto($canonical, $duplicate);

    // The unique (user_id, event_id) constraint means only one can survive,
    // and the canonical event's own reaction is the one that stands.
    expect(UserEventReaction::find($dropped->id))->toBeNull()
        ->and($kept->fresh()->reaction)->toBe(Reaction::Interested)
        ->and(UserEventReaction::where('user_id', $user->id)->count())->toBe(1);
});

it('repoints discovery logs at the canonical event', function () {
    $user = User::factory()->create();
    $canonical = Event::factory()->create();
    $duplicate = Event::factory()->create();

    $log = DiscoveryLog::factory()->create([
        'user_id' => $user->id,
        'event_id' => $duplicate->id,
    ]);

    $this->merger->mergeInto($canonical, $duplicate);

    expect($log->fresh()->event_id)->toBe($canonical->id);
});

it('is a no-op when asked to merge an event into itself', function () {
    $event = Event::factory()->create();

    $this->merger->mergeInto($event, $event);

    expect($event->fresh()->merged_into_id)->toBeNull();
});

it('ranks known sources above unknown ones', function () {
    expect($this->merger->sourcePriority('iabilet'))
        ->toBeGreaterThan($this->merger->sourcePriority('some_new_scraper'));
});
