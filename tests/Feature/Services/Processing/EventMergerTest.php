<?php

declare(strict_types=1);

use App\DTOs\RawEvent;
use App\Enums\Reaction;
use App\Jobs\ReverseProfileDeltaJob;
use App\Models\DiscoveryLog;
use App\Models\Event;
use App\Models\EventBookmark;
use App\Models\EventSource;
use App\Models\User;
use App\Models\UserEventReaction;
use App\Services\Processing\EventMerger;
use Illuminate\Support\Facades\Queue;

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

it('moves a bookmark onto the canonical event', function () {
    $user = User::factory()->create();
    $canonical = Event::factory()->create();
    $duplicate = Event::factory()->create();

    $bookmark = EventBookmark::factory()->processed()->create([
        'user_id' => $user->id,
        'event_id' => $duplicate->id,
    ]);

    $this->merger->mergeInto($canonical, $duplicate);

    expect($bookmark->fresh()->event_id)->toBe($canonical->id);
});

it('keeps the earlier created_at when both sides were bookmarked', function () {
    $user = User::factory()->create();
    $canonical = Event::factory()->create();
    $duplicate = Event::factory()->create();

    $kept = EventBookmark::factory()->create([
        'user_id' => $user->id,
        'event_id' => $canonical->id,
        'created_at' => now()->subDays(1),
    ]);
    $dropped = EventBookmark::factory()->create([
        'user_id' => $user->id,
        'event_id' => $duplicate->id,
        'created_at' => now()->subDays(5),
    ]);

    $this->merger->mergeInto($canonical, $duplicate);

    expect(EventBookmark::find($dropped->id))->toBeNull()
        ->and(EventBookmark::where('user_id', $user->id)->count())->toBe(1)
        ->and($kept->fresh()->created_at->toDateString())
        ->toBe(now()->subDays(5)->toDateString());
});

it('a merge does not destroy a bookmark held only on the duplicate', function () {
    $user = User::factory()->create();
    $canonical = Event::factory()->create();
    $duplicate = Event::factory()->create();

    // The user reacted to the canonical and bookmarked the duplicate: the
    // reaction collides and is dropped, but the bookmark must survive.
    UserEventReaction::create([
        'user_id' => $user->id,
        'event_id' => $canonical->id,
        'reaction' => Reaction::Interested,
        'is_processed' => true,
    ]);
    EventBookmark::factory()->processed()->create([
        'user_id' => $user->id,
        'event_id' => $duplicate->id,
    ]);

    $this->merger->mergeInto($canonical, $duplicate);

    expect(EventBookmark::where('user_id', $user->id)->where('event_id', $canonical->id)->exists())
        ->toBeTrue();
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

it('keeps first_seen_at at the original sighting when a source is re-scraped', function () {
    $event = Event::factory()->create();

    $raw = new RawEvent(
        title: 'Concert Phoenix',
        description: null,
        sourceUrl: 'https://iabilet.ro/bilete/concert-phoenix-12345/',
        sourceId: '12345',
        source: 'iabilet',
        venue: 'Sala Capitol',
        address: null,
        city: 'Timișoara',
        startsAt: '2026-09-10 18:00:00',
        endsAt: null,
        priceMin: null,
        priceMax: null,
        currency: 'RON',
        isFree: false,
        imageUrl: null,
    );

    $this->travelTo('2026-08-01 09:00:00');
    $first = $this->merger->attachSource($event, $raw, 'Europe/Bucharest');
    $firstSeen = $first->first_seen_at;

    $this->travelTo('2026-08-20 09:00:00');
    $second = $this->merger->attachSource($event, $raw, 'Europe/Bucharest');

    expect($second->id)->toBe($first->id)
        ->and($second->fresh()->first_seen_at->toDateTimeString())
        ->toBe($firstSeen->toDateTimeString())
        ->and($second->fresh()->last_seen_at->toDateTimeString())
        ->toBe('2026-08-20 09:00:00');
});

it('reverses a dropped reaction ledger instead of stranding it in the profile', function () {
    // The collision branch deletes the duplicate's row, and that row's ledger is
    // the only thing that could ever reverse its delta. Dedupe runs nightly, so
    // stranding it would accumulate drift silently.
    Queue::fake();

    $user = User::factory()->create();
    $canonical = Event::factory()->create();
    $duplicate = Event::factory()->create();

    UserEventReaction::create([
        'user_id' => $user->id,
        'event_id' => $canonical->id,
        'reaction' => Reaction::Interested,
        'is_processed' => true,
    ]);
    UserEventReaction::create([
        'user_id' => $user->id,
        'event_id' => $duplicate->id,
        'reaction' => Reaction::Interested,
        'applied_deltas' => ['music' => 0.15],
        'is_processed' => true,
    ]);

    $this->merger->mergeInto($canonical, $duplicate);

    Queue::assertPushed(ReverseProfileDeltaJob::class, fn ($job) => $job->userId === $user->id
        && $job->appliedDeltas === ['music' => 0.15]);
});

it('reverses a dropped bookmark ledger on collision', function () {
    Queue::fake();

    $user = User::factory()->create();
    $canonical = Event::factory()->create();
    $duplicate = Event::factory()->create();

    EventBookmark::factory()->processed()->create([
        'user_id' => $user->id,
        'event_id' => $canonical->id,
    ]);
    EventBookmark::factory()->processed()->create([
        'user_id' => $user->id,
        'event_id' => $duplicate->id,
        'applied_deltas' => ['music' => 0.20],
    ]);

    $this->merger->mergeInto($canonical, $duplicate);

    Queue::assertPushed(ReverseProfileDeltaJob::class, fn ($job) => $job->appliedDeltas === ['music' => 0.20]);
});

it('does not dispatch a reversal for a dropped row with no ledger', function () {
    Queue::fake();

    $user = User::factory()->create();
    $canonical = Event::factory()->create();
    $duplicate = Event::factory()->create();

    EventBookmark::factory()->create(['user_id' => $user->id, 'event_id' => $canonical->id]);
    EventBookmark::factory()->create(['user_id' => $user->id, 'event_id' => $duplicate->id]);

    $this->merger->mergeInto($canonical, $duplicate);

    Queue::assertNotPushed(ReverseProfileDeltaJob::class);
});
