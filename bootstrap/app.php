<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        // Every /api route is limited by the named `api` limiter. Without this
        // call the framework adds no throttle to the group at all, and every
        // authenticated endpoint was unlimited. Sanctum's stateful/SPA mode is
        // deliberately not enabled: nothing under /api needs cookie auth, and
        // it would put CSRF in front of every mobile call.
        $middleware->throttleApi();

        // Sanctum's ability middleware is not auto-registered.
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);

        // The signed email reaction links carry their own authentication in the
        // URL signature, which the `signed` middleware enforces on both the GET
        // and the POST. Requiring a CSRF token on top adds no security and does
        // add a failure mode: these links open in mail-client webviews that
        // routinely drop or partition the session cookie issued by the GET, and
        // the POST would then 419 with a bare English error page after the user
        // has already been told what is about to happen.
        // The unsubscribe link is the same shape: signed, opened from a mail
        // client, and a 419 there would leave someone unable to stop mail they
        // have explicitly asked to stop.
        $middleware->preventRequestForgery(except: [
            'reactions/*',
            'unsubscribe/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Scoped to /api/v1 inside the renderer; everything else falls through
        // to the framework's default rendering untouched.
        $exceptions->render(new ApiExceptionRenderer);
    })->create();
