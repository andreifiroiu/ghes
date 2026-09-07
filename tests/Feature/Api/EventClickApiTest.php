<?php

declare(strict_types=1);

use App\Enums\ActivityType;
use App\Jobs\ProcessActivitySignalJob;
use App\Models\Event;
use App\Models\EventSource;
use App\Models\User;
use App\Models\UserActivityLog;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user, ['*']);
});

it('logs the click, nudges the profile and returns the stored url', function () {
    $event = Event::factory()->create(['source' => 'iabilet', 'source_url' => 'https://iabilet.example/e/1']);

    $this->withHeaders(browserHeaders())
        ->postJson("/api/v1/events/{$event->id}/click")
        ->assertOk()
        ->assertExactJson(['data' => ['url' => 'https://iabilet.example/e/1', 'source' => 'iabilet']]);

    $log = UserActivityLog::ofType(ActivityType::EventClick)->sole();
    expect($log->user_id)->toBe($this->user->id)
        ->and($log->event_id)->toBe($event->id)
        ->and($log->context['authenticated'])->toBeTrue();

    Queue::assertPushed(ProcessActivitySignalJob::class);
});

it('selects among the event\'s own providers and never a caller-supplied url', function () {
    $event = Event::factory()->create(['source' => 'iabilet', 'source_url' => 'https://iabilet.example/e/1']);
    EventSource::factory()->create(['event_id' => $event->id, 'source' => 'entertix', 'source_url' => 'https://entertix.example/e/1']);

    $this->postJson("/api/v1/events/{$event->id}/click", ['source' => 'entertix'])
        ->assertOk()
        ->assertJsonPath('data.url', 'https://entertix.example/e/1');

    // An unknown source falls back to the canonical URL — it never redirects
    // anywhere the caller named.
    $this->postJson("/api/v1/events/{$event->id}/click", ['source' => 'https://evil.example'])
        ->assertOk()
        ->assertJsonPath('data.url', 'https://iabilet.example/e/1');
});

it('resolves a merged duplicate to its canonical event', function () {
    $canonical = Event::factory()->create(['source_url' => 'https://iabilet.example/e/1']);
    $duplicate = Event::factory()->create(['merged_into_id' => $canonical->id]);

    $this->postJson("/api/v1/events/{$duplicate->id}/click")
        ->assertOk()
        ->assertJsonPath('data.url', 'https://iabilet.example/e/1');

    expect(UserActivityLog::ofType(ActivityType::EventClick)->sole()->event_id)->toBe($canonical->id);
});

it('answers 404 for a hidden event', function () {
    $event = Event::factory()->create(['is_hidden' => true]);

    $this->postJson("/api/v1/events/{$event->id}/click")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    Queue::assertNothingPushed();
});

it('records the screen the click came from', function () {
    $event = Event::factory()->create();

    $this->postJson("/api/v1/events/{$event->id}/click", ['from' => 'dashboard'])->assertOk();

    expect(UserActivityLog::ofType(ActivityType::EventClick)->sole()->surface->value)->toBe('dashboard');
});
