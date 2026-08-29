<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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

        // The signed email reaction links carry their own authentication in the
        // URL signature, which the `signed` middleware enforces on both the GET
        // and the POST. Requiring a CSRF token on top adds no security and does
        // add a failure mode: these links open in mail-client webviews that
        // routinely drop or partition the session cookie issued by the GET, and
        // the POST would then 419 with a bare English error page after the user
        // has already been told what is about to happen.
        $middleware->validateCsrfTokens(except: [
            'reactions/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
