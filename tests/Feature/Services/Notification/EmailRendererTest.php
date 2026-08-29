<?php

declare(strict_types=1);

use App\Enums\EventCategory;
use App\Models\Event;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notification\EmailRenderer;

beforeEach(function () {
    $this->renderer = new EmailRenderer;
});

it('renders an HTML email with recommended events', function () {
    $user = User::factory()->create(['name' => 'Alice']);

    $event = Event::factory()->create([
        'title' => 'Jazz Night at Control',
        'category' => EventCategory::Music,
        'venue' => 'Control Club',
        'starts_at' => now()->addDays(3),
        'description' => 'Live jazz every Friday night.',
        'is_free' => true,
    ]);

    $notification = Notification::factory()->create([
        'user_id' => $user->id,
        'event_ids' => [$event->id],
        'discovery_event_ids' => [],
        'subject' => 'Evenimentele tale Ghes',
    ]);

    $html = $this->renderer->render($notification);

    expect($html)->toContain('Jazz Night at Control');
    expect($html)->toContain('Control Club');
    expect($html)->toContain('Music');
    expect($html)->toContain('Gratuit');
    expect($html)->toContain('Alice');
    expect($html)->toContain('Mă interesează');
    expect($html)->toContain('Nu-i pentru mine');
    expect($html)->toContain('Salvează');
});

it('includes signed reaction URLs', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    $notification = Notification::factory()->create([
        'user_id' => $user->id,
        'event_ids' => [$event->id],
        'discovery_event_ids' => [],
    ]);

    $html = $this->renderer->render($notification);

    // Signed URLs should contain a signature parameter
    expect($html)->toContain('signature=');
    expect($html)->toContain('reactions/');
    expect($html)->toContain('interested');
    expect($html)->toContain('not_interested');
    expect($html)->toContain('saved');
});

it('renders discovery events in a separate section', function () {
    $user = User::factory()->create();

    $recommended = Event::factory()->create(['title' => 'Regular Event']);
    $discovery = Event::factory()->create(['title' => 'Surprise Event']);

    $notification = Notification::factory()->create([
        'user_id' => $user->id,
        'event_ids' => [$recommended->id],
        'discovery_event_ids' => [$discovery->id],
    ]);

    $html = $this->renderer->render($notification);

    expect($html)->toContain('Descoperă ceva nou');
    expect($html)->toContain('Descoperire');
    expect($html)->toContain('Surprise Event');
});

it('renders a valid HTML document', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    $notification = Notification::factory()->create([
        'user_id' => $user->id,
        'event_ids' => [$event->id],
        'discovery_event_ids' => [],
    ]);

    $html = $this->renderer->render($notification);

    expect($html)->toContain('<!doctype html>');
    expect($html)->toContain('</html>');
    expect($html)->toContain('Ghes');
});

it('handles empty event lists gracefully', function () {
    $user = User::factory()->create();

    $notification = Notification::factory()->create([
        'user_id' => $user->id,
        'event_ids' => [],
        'discovery_event_ids' => [],
    ]);

    $html = $this->renderer->render($notification);

    expect($html)->toContain('<!doctype html>');
    expect($html)->not->toContain('Descoperă ceva nou');
});

it('shows event pricing information', function () {
    $user = User::factory()->create();

    $event = Event::factory()->create([
        'is_free' => false,
        'price_min' => 50.0,
        'price_max' => 100.0,
        'currency' => 'RON',
    ]);

    $notification = Notification::factory()->create([
        'user_id' => $user->id,
        'event_ids' => [$event->id],
        'discovery_event_ids' => [],
    ]);

    $html = $this->renderer->render($notification);

    expect($html)->toContain('RON');
    expect($html)->toContain('50');
});
