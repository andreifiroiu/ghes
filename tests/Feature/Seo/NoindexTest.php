<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();
});

it('leaves the public pages indexable', function (string $url) {
    $response = $this->get($url);

    expect($response->headers->get('X-Robots-Tag'))->toBeNull()
        ->and((string) $response->getContent())->not->toContain('name="robots"');
})->with([
    'landing' => fn () => '/',
    'browse' => fn () => route('events.index'),
]);

it('leaves an event detail page indexable', function () {
    $event = Event::factory()->create(['starts_at' => now()->addWeek()]);

    $response = $this->get(route('events.show', $event));

    expect($response->headers->get('X-Robots-Tag'))->toBeNull();
});

it('noindexes a signed-in surface', function (string $route) {
    $response = $this->actingAs(User::factory()->create())->get(route($route));

    expect($response->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow');
})->with([
    'dashboard' => fn () => 'dashboard',
    'saved events' => fn () => 'events.saved',
    'profile' => fn () => 'profile.show',
    'notification settings' => fn () => 'settings.notifications',
    'onboarding' => fn () => 'onboarding',
]);

it('carries the noindex as a meta tag too, for the pages that have a head', function () {
    $html = (string) $this->actingAs(User::factory()->create())->get(route('profile.show'))->getContent();

    expect($html)->toContain('<meta name="robots" content="noindex, nofollow">');
});

it('noindexes the auth pages', function (string $route) {
    expect($this->get(route($route))->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow');
})->with([
    'login' => fn () => 'login',
    'register' => fn () => 'register',
    'forgot password' => fn () => 'password.request',
]);

it('sets the header on a redirect, which has no head to carry a meta tag', function () {
    $event = Event::factory()->create(['starts_at' => now()->addWeek()]);

    $response = $this->get(route('events.go', $event));

    $response->assertRedirect();

    expect($response->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow');
});

it('noindexes the admin area', function () {
    config(['eventpulse.admin_emails' => ['admin@ghes.ro']]);

    $admin = User::factory()->create(['email' => 'admin@ghes.ro']);

    expect($this->actingAs($admin)->get(route('admin.dashboard'))->headers->get('X-Robots-Tag'))
        ->toBe('noindex, nofollow');
});

it('noindexes a filtered browse without blocking the crawl through it', function () {
    Event::factory()->create(['category' => 'music', 'starts_at' => now()->addWeek()]);

    // `follow`, not `nofollow`: the filtered view is a route to the event pages
    // even though it is not worth indexing itself.
    expect((string) $this->get(route('events.index', ['category' => 'music']))->getContent())
        ->toContain('<meta name="robots" content="noindex, follow">');
});
