<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->withoutVite();
    $this->admin = User::factory()->create();
    config(['eventpulse.admin_emails' => [$this->admin->email]]);
});

/**
 * Two reports of the same night, from different providers.
 *
 * @param  array<string, mixed>  $overrides
 */
function duplicateEvent(array $overrides = []): Event
{
    return Event::factory()->create([
        'title' => 'Concert Phoenix',
        'city' => 'Timișoara',
        'venue' => 'Casa Tineretului',
        'starts_at' => Carbon::parse('2026-05-10 19:00', 'Europe/Bucharest')->utc(),
        ...$overrides,
    ]);
}

it('groups events that share a blocking key', function () {
    $fromAggregator = duplicateEvent(['source' => 'allevents']);
    $fromTicketing = duplicateEvent(['source' => 'iabilet']);
    duplicateEvent(['title' => 'Stand-up cu Micutzu', 'source' => 'iabilet']);

    $this->actingAs($this->admin)->get('/admin/events/duplicates')
        ->assertStatus(200)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Events/Duplicates')
            ->has('groups', 1)
            ->where('groups.0.reason', 'match_key')
            // iabilet outranks allevents, so it is suggested as the survivor.
            ->where('groups.0.events.0.id', $fromTicketing->id)
            ->where('groups.0.events.1.id', $fromAggregator->id));
});

it('surfaces near-miss titles only when scored matching is on', function () {
    duplicateEvent(['title' => 'Trupa Phoenix in concert', 'source' => 'allevents']);
    duplicateEvent(['title' => 'Trupa Phoenix in concert la Casa Tineretului', 'source' => 'iabilet']);

    $this->actingAs($this->admin)->get('/admin/events/duplicates')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('groups', 0));

    $this->actingAs($this->admin)->get('/admin/events/duplicates?fuzzy=1')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('groups', 1)
            ->where('groups.0.reason', 'score'));
});

it('leaves genuinely different events on the same night alone', function () {
    duplicateEvent(['title' => 'Concert Phoenix', 'source' => 'allevents']);
    duplicateEvent(['title' => 'Stand-up Comedy cu Micutzu', 'source' => 'iabilet']);

    $this->actingAs($this->admin)->get('/admin/events/duplicates?fuzzy=1')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('groups', 0));
});

it('limits the search to a city and date interval', function () {
    duplicateEvent(['source' => 'allevents']);
    duplicateEvent(['source' => 'iabilet']);

    $this->actingAs($this->admin)->get('/admin/events/duplicates?city=Cluj-Napoca')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('groups', 0));

    $this->actingAs($this->admin)->get('/admin/events/duplicates?date_from=2026-06-01')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('groups', 0));

    $this->actingAs($this->admin)->get('/admin/events/duplicates?date_from=2026-05-10&date_to=2026-05-10')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('groups', 1));
});

it('merges the chosen duplicates into the canonical event', function () {
    $canonical = duplicateEvent(['source' => 'iabilet']);
    $duplicate = duplicateEvent(['source' => 'allevents']);
    $untouched = duplicateEvent(['source' => 'zilesinopti']);

    $this->actingAs($this->admin)
        ->post('/admin/events/merge', [
            'canonical_id' => $canonical->id,
            'duplicate_ids' => [$duplicate->id],
        ])
        ->assertRedirect();

    expect($duplicate->fresh()->merged_into_id)->toBe($canonical->id)
        ->and($canonical->fresh()->merged_into_id)->toBeNull()
        ->and($untouched->fresh()->merged_into_id)->toBeNull();
});

it('refuses to merge into an event that is itself a duplicate', function () {
    $canonical = duplicateEvent(['source' => 'iabilet']);
    $merged = Event::factory()->merged($canonical)->create();
    $other = duplicateEvent(['source' => 'allevents']);

    $this->actingAs($this->admin)
        ->post('/admin/events/merge', [
            'canonical_id' => $merged->id,
            'duplicate_ids' => [$other->id],
        ])
        ->assertSessionHas('error');

    expect($other->fresh()->merged_into_id)->toBeNull();
});

it('skips duplicates that were already merged', function () {
    $canonical = duplicateEvent(['source' => 'iabilet']);
    $alreadyMerged = Event::factory()->merged($canonical)->create();

    $this->actingAs($this->admin)
        ->post('/admin/events/merge', [
            'canonical_id' => $canonical->id,
            'duplicate_ids' => [$alreadyMerged->id],
        ])
        ->assertSessionHas('error');

    expect($alreadyMerged->fresh()->merged_into_id)->toBe($canonical->id);
});

it('rejects merging an event into itself', function () {
    $event = duplicateEvent();

    $this->actingAs($this->admin)
        ->post('/admin/events/merge', [
            'canonical_id' => $event->id,
            'duplicate_ids' => [$event->id],
        ])
        ->assertSessionHasErrors('duplicate_ids.0');
});

it('keeps the duplicate screen away from non-admins', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/events/duplicates')
        ->assertForbidden();
});

it('keeps merging away from non-admins', function () {
    $canonical = duplicateEvent(['source' => 'iabilet']);
    $duplicate = duplicateEvent(['source' => 'allevents']);

    $this->actingAs(User::factory()->create())
        ->post('/admin/events/merge', [
            'canonical_id' => $canonical->id,
            'duplicate_ids' => [$duplicate->id],
        ])
        ->assertForbidden();

    expect($duplicate->fresh()->merged_into_id)->toBeNull();
});
