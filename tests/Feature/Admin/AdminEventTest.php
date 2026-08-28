<?php

declare(strict_types=1);

use App\Enums\EventCategory;
use App\Jobs\ClassifyEventJob;
use App\Models\Event;
use App\Models\User;
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
