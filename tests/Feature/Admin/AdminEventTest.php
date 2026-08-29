<?php

declare(strict_types=1);

use App\Enums\EventCategory;
use App\Jobs\ClassifyEventJob;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->withoutVite();
    $this->admin = User::factory()->create();
    config(['eventpulse.admin_emails' => [$this->admin->email]]);
});

it('lists events', function () {
    Event::factory()->count(3)->create();

    $this->actingAs($this->admin)->get('/admin/events')
        ->assertStatus(200)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Events/Index')
            ->has('events.data', 3));
});

it('filters events by search', function () {
    Event::factory()->create(['title' => 'Jazz Festival']);
    Event::factory()->create(['title' => 'Rock Concert']);

    $this->actingAs($this->admin)->get('/admin/events?search=Jazz')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('events.data', 1));
});

it('updates an event', function () {
    $event = Event::factory()->create(['title' => 'Old Title']);

    $this->actingAs($this->admin)
        ->put("/admin/events/{$event->id}", ['title' => 'New Title', 'category' => 'music'])
        ->assertRedirect(route('admin.events.index'));

    expect($event->fresh()->title)->toBe('New Title')
        ->and($event->fresh()->category)->toBe(EventCategory::Music);
});

it('deletes an event', function () {
    $event = Event::factory()->create();

    $this->actingAs($this->admin)
        ->delete("/admin/events/{$event->id}")
        ->assertRedirect(route('admin.events.index'));

    expect(Event::find($event->id))->toBeNull();
});

it('toggles an event hidden flag', function () {
    $event = Event::factory()->create(['is_hidden' => false]);

    $this->actingAs($this->admin)->post("/admin/events/{$event->id}/hide");
    expect($event->fresh()->is_hidden)->toBeTrue();

    $this->actingAs($this->admin)->post("/admin/events/{$event->id}/hide");
    expect($event->fresh()->is_hidden)->toBeFalse();
});

it('boosts an event popularity', function () {
    $event = Event::factory()->create(['popularity_score' => 10]);

    $this->actingAs($this->admin)->post("/admin/events/{$event->id}/feature");

    expect($event->fresh()->popularity_score)->toBe(35);
});

it('queues re-classification and resets the flag', function () {
    Bus::fake([ClassifyEventJob::class]);
    $event = Event::factory()->create(['is_classified' => true]);

    $this->actingAs($this->admin)
        ->post("/admin/events/{$event->id}/reprocess", ['action' => 'classify'])
        ->assertRedirect();

    Bus::assertDispatched(ClassifyEventJob::class, fn ($job) => $job->eventId === $event->id);
    expect($event->fresh()->is_classified)->toBeFalse();
});

it('filters events by source', function () {
    Event::factory()->create(['source' => 'iabilet']);
    Event::factory()->create(['source' => 'allevents']);

    $this->actingAs($this->admin)->get('/admin/events?source=iabilet')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events.data', 1)
            ->where('events.data.0.source', 'iabilet'));
});

it('filters events by a date interval in the city timezone', function () {
    // 01:00 local on the 5th of May is 22:00 UTC on the 4th; asking for the
    // 5th must still find it.
    $inside = Event::factory()->create([
        'starts_at' => Carbon::parse('2026-05-05 01:00', 'Europe/Bucharest')->utc(),
    ]);
    Event::factory()->create([
        'starts_at' => Carbon::parse('2026-05-08 20:00', 'Europe/Bucharest')->utc(),
    ]);

    $this->actingAs($this->admin)
        ->get('/admin/events?date_from=2026-05-05&date_to=2026-05-06')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events.data', 1)
            ->where('events.data.0.id', $inside->id));
});

it('ignores an unparseable date bound instead of emptying the list', function () {
    Event::factory()->count(2)->create();

    $this->actingAs($this->admin)->get('/admin/events?date_from=not-a-date')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('events.data', 2));
});

it('hides merged duplicates unless they are asked for', function () {
    $canonical = Event::factory()->create();
    $duplicate = Event::factory()->merged($canonical)->create();

    $this->actingAs($this->admin)->get('/admin/events')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events.data', 1)
            ->where('events.data.0.id', $canonical->id));

    $this->actingAs($this->admin)->get('/admin/events?status=merged')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events.data', 1)
            ->where('events.data.0.id', $duplicate->id));
});

it('sorts events by start date', function () {
    $later = Event::factory()->create(['starts_at' => now()->addDays(10)]);
    $sooner = Event::factory()->create(['starts_at' => now()->addDay()]);

    $this->actingAs($this->admin)->get('/admin/events?sort=starts_at&direction=asc')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('events.data.0.id', $sooner->id)
            ->where('events.data.1.id', $later->id));
});

it('rejects an unknown sort column', function () {
    Event::factory()->count(2)->create();

    // Falls back to created_at rather than passing the input to the database.
    $this->actingAs($this->admin)->get('/admin/events?sort=source_url;drop')
        ->assertStatus(200)
        ->assertInertia(fn (AssertableInertia $page) => $page->has('events.data', 2));
});

it('offers every source that produced an event as a filter option', function () {
    Event::factory()->create(['source' => 'iabilet']);
    Event::factory()->create(['source' => 'allevents']);
    Event::factory()->create(['source' => 'allevents']);

    $this->actingAs($this->admin)->get('/admin/events')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sources', ['allevents', 'iabilet']));
});

it('paginates the admin events list at the configured page size', function () {
    config(['eventpulse.pagination.admin_events' => 2]);
    Event::factory()->count(3)->create();

    $this->actingAs($this->admin)->get('/admin/events')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events.data', 2)
            ->where('events.meta.per_page', 2));
});
