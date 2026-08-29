<?php

declare(strict_types=1);

use App\Enums\ActivitySurface;
use App\Enums\ActivityType;
use App\Jobs\ProcessActivitySignalJob;
use App\Models\Event;
use App\Models\EventSource;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserActivityLog;
use Illuminate\Support\Facades\Queue;

it('records a click and forwards to the event source', function () {
    $event = Event::factory()->create(['source_url' => 'https://iabilet.ro/concert-x']);

    $this->withHeaders(browserHeaders())
        ->get("/go/{$event->id}")
        ->assertRedirect('https://iabilet.ro/concert-x');

    $log = UserActivityLog::sole();

    expect($log->type)->toBe(ActivityType::EventClick)
        ->and($log->event_id)->toBe($event->id)
        ->and($log->is_bot)->toBeFalse();
});

it('redirects with a 302 so repeat clicks keep reaching us', function () {
    $event = Event::factory()->create(['source_url' => 'https://iabilet.ro/concert-x']);

    $this->withHeaders(browserHeaders())
        ->get("/go/{$event->id}")
        ->assertStatus(302);
});

it('ignores a destination supplied in the query string', function () {
    $event = Event::factory()->create(['source_url' => 'https://iabilet.ro/concert-x']);

    // The whole point of reading the destination off the row: a redirector that
    // honoured this parameter would be an open redirect on a public route.
    $this->withHeaders(browserHeaders())
        ->get("/go/{$event->id}?source_url=https://evil.example/phish&url=https://evil.example")
        ->assertRedirect('https://iabilet.ro/concert-x');
});

it('records the surface the click came from', function () {
    $event = Event::factory()->create();

    $this->withHeaders(browserHeaders())->get("/go/{$event->id}?from=digest");

    expect(UserActivityLog::sole()->surface)->toBe(ActivitySurface::Digest);
});

it('falls back to a default surface for an unrecognised one', function () {
    $event = Event::factory()->create();

    // A junk `from` must not cost the user their redirect.
    $this->withHeaders(browserHeaders())
        ->get("/go/{$event->id}?from=not-a-surface")
        ->assertStatus(302);

    expect(UserActivityLog::sole()->surface)->toBe(ActivitySurface::EventsIndex);
});

it('follows a merged duplicate to the surviving event', function () {
    $canonical = Event::factory()->create(['source_url' => 'https://iabilet.ro/canonical']);
    $duplicate = Event::factory()->create([
        'source_url' => 'https://iabilet.ro/duplicate',
        'merged_into_id' => $canonical->id,
    ]);

    $this->withHeaders(browserHeaders())
        ->get("/go/{$duplicate->id}")
        ->assertRedirect('https://iabilet.ro/canonical');

    expect(UserActivityLog::sole()->event_id)->toBe($canonical->id);
});

it('404s on a hidden event', function () {
    $event = Event::factory()->create(['is_hidden' => true]);

    $this->withHeaders(browserHeaders())->get("/go/{$event->id}")->assertNotFound();

    expect(UserActivityLog::count())->toBe(0);
});

it('nudges the profile only for an authenticated click', function () {
    Queue::fake();

    $user = User::factory()->create();
    $event = Event::factory()->create();

    $this->actingAs($user)->withHeaders(browserHeaders())->get("/go/{$event->id}");

    Queue::assertPushed(ProcessActivitySignalJob::class, 1);
});

it('logs a guest click without dispatching a profile job', function () {
    Queue::fake();

    $event = Event::factory()->create();

    $this->withHeaders(browserHeaders())->get("/go/{$event->id}");

    expect(UserActivityLog::sole()->user_id)->toBeNull();
    Queue::assertNotPushed(ProcessActivitySignalJob::class);
});

it('attributes an unauthenticated digest click to the recipient but does not score it', function () {
    Queue::fake();

    $user = User::factory()->create();
    $event = Event::factory()->create();
    $notification = Notification::factory()->for($user)->create();

    $this->withHeaders(browserHeaders())
        ->get("/go/{$event->id}?from=digest&n={$notification->id}");

    $log = UserActivityLog::sole();

    // Attribution without authority: we know whose digest it was, but a signed
    // -out fetch of a link in an email is not evidence that person clicked it.
    expect($log->user_id)->toBe($user->id)
        ->and($log->notification_id)->toBe($notification->id)
        ->and($log->context['authenticated'])->toBeFalse();

    Queue::assertNotPushed(ProcessActivitySignalJob::class);
});

it('ignores an unresolvable notification id rather than failing the redirect', function () {
    $event = Event::factory()->create(['source_url' => 'https://iabilet.ro/concert-x']);

    $this->withHeaders(browserHeaders())
        ->get("/go/{$event->id}?n=".fake()->uuid())
        ->assertRedirect('https://iabilet.ro/concert-x');

    expect(UserActivityLog::sole()->notification_id)->toBeNull();
});

it('flags a mail scanner as a bot and keeps it out of the profile', function () {
    Queue::fake();

    $user = User::factory()->create();
    $event = Event::factory()->create();

    $this->actingAs($user)
        ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; ProofPoint URL Defense)'])
        ->get("/go/{$event->id}")
        ->assertStatus(302);

    // Still recorded — it is real traffic — but inert.
    expect(UserActivityLog::sole()->is_bot)->toBeTrue();
    Queue::assertNotPushed(ProcessActivitySignalJob::class);
});

it('survives a junk notification id on the public redirect', function () {
    $event = Event::factory()->create(['source_url' => 'https://iabilet.ro/concert-x']);

    // notification_id is a Postgres uuid column, so an unguarded lookup on
    // arbitrary text raises a QueryException — a 500 anyone could trigger by
    // appending a query param to a public link.
    $this->withHeaders(browserHeaders())
        ->get("/go/{$event->id}?n=not-a-uuid")
        ->assertRedirect('https://iabilet.ro/concert-x');

    expect(UserActivityLog::sole()->notification_id)->toBeNull();
});

it('survives array-shaped query params on the public redirect', function () {
    $event = Event::factory()->create(['source_url' => 'https://iabilet.ro/concert-x']);

    // Anyone can shape the query string on a public link, and an array where a
    // string is expected is a TypeError under strict_types.
    $this->withHeaders(browserHeaders())
        ->get("/go/{$event->id}?n[]=a&n[]=b&from[]=digest")
        ->assertRedirect('https://iabilet.ro/concert-x');

    expect(UserActivityLog::sole()->surface)->toBe(ActivitySurface::EventsIndex);
});

it('sends the click to the provider the reader picked', function () {
    $event = Event::factory()->create(['source_url' => 'https://iabilet.ro/canonical']);
    EventSource::factory()->forSource('allevents')->create([
        'event_id' => $event->id,
        'source_url' => 'https://allevents.in/concert-x',
    ]);

    // The detail page offers a button per provider that listed the event, so
    // `s` says which was chosen.
    $this->withHeaders(browserHeaders())
        ->get("/go/{$event->id}?s=allevents")
        ->assertRedirect('https://allevents.in/concert-x');

    expect(UserActivityLog::sole()->context['source'])->toBe('allevents');
});

it('ignores a provider the event was never listed by', function () {
    $event = Event::factory()->create(['source_url' => 'https://iabilet.ro/canonical']);
    EventSource::factory()->forSource('allevents')->create([
        'event_id' => $event->id,
        'source_url' => 'https://allevents.in/concert-x',
    ]);

    // `s` selects among this event's own stored URLs — it never supplies one.
    // Naming a provider that did not list this event falls back to the
    // canonical URL rather than trusting the caller.
    $this->withHeaders(browserHeaders())
        ->get("/go/{$event->id}?s=evil-source")
        ->assertRedirect('https://iabilet.ro/canonical');
});

it('ignores an array-shaped provider selection', function () {
    $event = Event::factory()->create(['source_url' => 'https://iabilet.ro/canonical']);

    $this->withHeaders(browserHeaders())
        ->get("/go/{$event->id}?s[]=a&s[]=b")
        ->assertRedirect('https://iabilet.ro/canonical');
});
