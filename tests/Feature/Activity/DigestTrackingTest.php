<?php

declare(strict_types=1);

use App\Enums\ActivitySurface;
use App\Enums\ActivityType;
use App\Enums\NotificationChannel;
use App\Models\Event;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\Activity\ActivityReporter;
use App\Services\Notification\EmailRenderer;
use App\Services\Notification\NotificationDispatcher;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    $this->renderer = new EmailRenderer;
    $this->user = User::factory()->create();
    $this->event = Event::factory()->create(['title' => 'Jazz Night']);
    $this->notification = Notification::factory()->create([
        'user_id' => $this->user->id,
        'event_ids' => [$this->event->id],
        'discovery_event_ids' => [],
    ]);
});

it('embeds the open pixel in the digest', function () {
    $html = $this->renderer->render($this->notification);

    expect($html)->toContain(route('notifications.open', ['notification' => $this->notification->id], false));
});

it('links each card through the tracked redirect', function () {
    $html = $this->renderer->render($this->notification);

    // The digest had no event links at all before tracking; this is the link
    // that makes a digest click a thing that can happen.
    expect($html)->toContain(route('events.go', ['event' => $this->event->id], false))
        ->and($html)->toContain('from=digest')
        ->and($html)->toContain("n={$this->notification->id}")
        ->and($html)->toContain('Vezi detalii');
});

it('carries the notification id inside the signed reaction urls', function () {
    $html = $this->renderer->render($this->notification);

    preg_match('/href="([^"]*reactions[^"]*interested[^"]*)"/', $html, $matches);
    $url = html_entity_decode($matches[1]);

    expect($url)->toContain("n={$this->notification->id}");

    // Extra params are covered by the signature, so attribution cannot be
    // forged by editing the link.
    $this->post($url)->assertOk();
    $this->post(str_replace($this->notification->id, (string) fake()->uuid(), $url))->assertForbidden();
});

it('records an email click when a reaction is confirmed', function () {
    $url = URL::temporarySignedRoute('reactions.email', now()->addDay(), [
        'user' => $this->user->id,
        'event' => $this->event->id,
        'reaction' => 'interested',
        'n' => $this->notification->id,
    ]);

    $this->post($url)->assertOk();

    $log = UserActivityLog::ofType(ActivityType::EmailClick)->sole();

    expect($log->surface)->toBe(ActivitySurface::Digest)
        ->and($log->notification_id)->toBe($this->notification->id)
        ->and($log->context['action'])->toBe('interested');
});

it('does not record a click when a scanner merely fetches the link', function () {
    $url = URL::temporarySignedRoute('reactions.email', now()->addDay(), [
        'user' => $this->user->id,
        'event' => $this->event->id,
        'reaction' => 'interested',
        'n' => $this->notification->id,
    ]);

    // The GET renders a confirmation page and writes nothing — the same reason
    // it does not record a reaction.
    $this->get($url)->assertOk();

    expect(UserActivityLog::count())->toBe(0);
});

it('labels an email reaction with the digest surface', function () {
    $url = URL::temporarySignedRoute('reactions.email', now()->addDay(), [
        'user' => $this->user->id,
        'event' => $this->event->id,
        'reaction' => 'saved',
    ]);

    $this->post($url)->assertOk();

    expect(UserActivityLog::ofType(ActivityType::BookmarkAdded)->sole()->surface)
        ->toBe(ActivitySurface::Digest);
});

it('still records the click when the notification id is missing or stale', function () {
    $url = URL::temporarySignedRoute('reactions.email', now()->addDay(), [
        'user' => $this->user->id,
        'event' => $this->event->id,
        'reaction' => 'interested',
        'n' => fake()->uuid(),
    ]);

    // notification_id is a foreign key; trusting a stale id would fail the
    // insert and cost us the whole row.
    $this->post($url)->assertOk();

    expect(UserActivityLog::ofType(ActivityType::EmailClick)->sole()->notification_id)->toBeNull();
});

it('survives a junk notification id on the reaction confirm', function () {
    $url = URL::temporarySignedRoute('reactions.email', now()->addDay(), [
        'user' => $this->user->id,
        'event' => $this->event->id,
        'reaction' => 'interested',
        'n' => 'not-a-uuid',
    ]);

    $this->post($url)->assertOk();

    expect(UserActivityLog::ofType(ActivityType::EmailClick)->sole()->notification_id)->toBeNull();
});

it('logs an impression per event when the digest is actually sent', function () {
    $discovery = Event::factory()->create();
    $notification = Notification::factory()->create([
        'user_id' => $this->user->id,
        'event_ids' => [$this->event->id],
        'discovery_event_ids' => [$discovery->id],
        'sent_at' => null,
    ]);

    app(NotificationDispatcher::class)->dispatch($notification);

    $impressions = UserActivityLog::ofType(ActivityType::EventImpression)->get();

    // Without these the digest contributes clicks but no impressions, so the
    // click-through rate divides email clicks by web impressions — and can
    // exceed 100% on an email-first product.
    expect($impressions)->toHaveCount(2)
        ->and($impressions->pluck('event_id')->sort()->values()->all())
        ->toBe(collect([$this->event->id, $discovery->id])->sort()->values()->all())
        ->and($impressions->first()->surface)->toBe(ActivitySurface::Digest)
        ->and($impressions->first()->notification_id)->toBe($notification->id);
});

it('does not flag digest impressions as bot traffic', function () {
    $notification = Notification::factory()->create([
        'user_id' => $this->user->id,
        'event_ids' => [$this->event->id],
        'discovery_event_ids' => [],
        'sent_at' => null,
    ]);

    app(NotificationDispatcher::class)->dispatch($notification);

    // Written by a queue worker, where there is no browser to send a
    // User-Agent. Classifying them by the absent header would drop them right
    // back out of the denominator they exist to provide.
    expect(UserActivityLog::ofType(ActivityType::EventImpression)->sole()->is_bot)->toBeFalse();
});

it('counts an open even though the mail client fetches the pixel as a proxy', function () {
    $notification = Notification::factory()->create([
        'user_id' => $this->user->id,
        'event_ids' => [],
        'discovery_event_ids' => [],
        'sent_at' => now(),
        'channel' => NotificationChannel::Email,
    ]);

    $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (via ggpht.com GoogleImageProxy)'])
        ->get(URL::signedRoute('notifications.open', ['notification' => $notification->id]))
        ->assertOk();

    // The pixel is by construction fetched by the image proxy, never by the
    // reader's browser. Filtering that as bot traffic would report zero opens
    // forever, right beside an open-rate tile computed from opened_at showing
    // a real number — one page, two contradictory answers.
    $summary = app(ActivityReporter::class)->summary();

    expect($summary['counts'][ActivityType::EmailOpen->value])->toBe(1)
        ->and($summary['digest']['opened'])->toBe(1)
        ->and($summary['digest']['open_rate'])->toBe(1.0);
});
