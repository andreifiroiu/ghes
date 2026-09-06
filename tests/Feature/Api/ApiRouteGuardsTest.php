<?php

declare(strict_types=1);

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/**
 * Every v1 route outside the public set must carry both a bearer-token check
 * and a throttle, and every admin route the admin gate. Asserted by
 * inspection so that a route added without the middleware fails here, before
 * a test that happens to exercise it is ever written — this is the durable
 * defence against the global throttle silently disappearing again.
 */
const PUBLIC_V1_ROUTES = [
    'api/v1/meta',
    'api/v1/auth/register',
    'api/v1/auth/login',
];

/**
 * @return array<int, RouteInstance>
 */
function v1Routes(): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        fn (RouteInstance $route): bool => str_starts_with($route->uri(), 'api/v1/'),
    ));
}

/**
 * The middleware that will actually run, with groups expanded and aliases
 * resolved — `$route->middleware()` only lists `api` and `auth:sanctum` as
 * written, which says nothing about what the `api` group contains.
 *
 * @return list<string>
 */
function resolvedMiddleware(RouteInstance $route): array
{
    return app('router')->gatherRouteMiddleware($route);
}

it('registers the v1 routes', function () {
    expect(v1Routes())->not->toBeEmpty();
});

it('throttles every v1 route, public ones included', function () {
    foreach (v1Routes() as $route) {
        $throttled = array_filter(
            resolvedMiddleware($route),
            fn (string $middleware): bool => str_starts_with($middleware, ThrottleRequests::class.':'),
        );

        expect($throttled)->not->toBeEmpty("{$route->uri()} carries no throttle middleware");
    }
});

it('requires a bearer token on every v1 route outside the public set', function () {
    foreach (v1Routes() as $route) {
        if (in_array($route->uri(), PUBLIC_V1_ROUTES, true)) {
            continue;
        }

        expect(in_array(Authenticate::class.':sanctum', resolvedMiddleware($route), true))
            ->toBeTrue("{$route->uri()} is reachable without a token");
    }
});

it('keeps the public set exactly as documented', function () {
    $public = [];

    foreach (v1Routes() as $route) {
        if (! in_array(Authenticate::class.':sanctum', resolvedMiddleware($route), true)) {
            $public[] = $route->uri();
        }
    }

    sort($public);
    $expected = PUBLIC_V1_ROUTES;
    sort($expected);

    expect($public)->toBe($expected);
});

it('gates every v1 admin route behind the admin gate', function () {
    $adminRoutes = array_filter(
        v1Routes(),
        fn (RouteInstance $route): bool => str_starts_with($route->uri(), 'api/v1/admin/'),
    );

    expect($adminRoutes)->not->toBeEmpty();

    foreach ($adminRoutes as $route) {
        expect(in_array(Authorize::class.':access-admin', resolvedMiddleware($route), true))
            ->toBeTrue("{$route->uri()} is not gated");
    }
});

it('never enables stateful sanctum on the api group', function () {
    foreach (v1Routes() as $route) {
        expect(resolvedMiddleware($route))
            ->not->toContain(EnsureFrontendRequestsAreStateful::class);
    }
});
