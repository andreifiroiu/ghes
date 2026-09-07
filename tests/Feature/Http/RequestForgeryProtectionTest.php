<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

// PreventRequestForgery returns early when the app is running in console under
// the "testing" environment, so a request-level 419 assertion is impossible
// here. The exclusion list on the resolved middleware is the deterministic
// surface that survives the Laravel 13 rename from validateCsrfTokens() to
// preventRequestForgery().

it('keeps the signed email reaction links exempt from request forgery protection', function (): void {
    $middleware = app(PreventRequestForgery::class);

    expect($middleware->getExcludedPaths())->toContain('reactions/*');
});

it('matches the exclusion against the reaction route as it is actually registered', function (): void {
    $user = User::factory()->create();
    $event = Event::withoutSyncingToSearch(fn () => Event::factory()->create());

    $url = URL::signedRoute('reactions.email.confirm', [
        'user' => $user,
        'event' => $event,
        'reaction' => 'like',
    ]);

    $request = Request::create($url, 'POST');
    $patterns = app(PreventRequestForgery::class)->getExcludedPaths();

    // Moving the route under another prefix must break this test, or every
    // emailed reaction POST starts answering 419 in production.
    expect($request->is(...$patterns))->toBeTrue();
});

it('registers the Laravel 13 middleware in the web group rather than a deprecated alias', function (): void {
    $webGroup = app('router')->getMiddlewareGroups()['web'];

    expect($webGroup)
        ->toContain(PreventRequestForgery::class)
        ->not->toContain(VerifyCsrfToken::class)
        ->not->toContain(ValidateCsrfToken::class);
});
