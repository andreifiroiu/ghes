<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ApiVersionGoneController;
use App\Http\Middleware\EnforceMinimumAppVersion;
use Illuminate\Support\Facades\Route;

// The API is versioned by path, with no unversioned alias: a second mount
// would double the surface the throttle and ability middleware must cover,
// and a forgotten alias is exactly how hardening gets bypassed. Each version
// lives in its own file under routes/api/.
Route::prefix('v1')
    ->name('api.v1.')
    ->middleware(EnforceMinimumAppVersion::class)
    ->group(base_path('routes/api/v1.php'));

// Anything else under /api is a version this server does not serve — in
// practice an old build still calling the pre-versioning paths. Answer
// "upgrade", not "server broken". The pattern excludes `v1/…` so an
// unknown *v1* path still 404s through the error envelope instead of being
// misreported as a retired version. Inside the `api` prefix, so it cannot
// shadow `/up`, Horizon or the log viewer.
Route::any('{path}', ApiVersionGoneController::class)
    ->where('path', '^(?!v1(?:/|$)).*$')
    ->name('api.version-gone');
