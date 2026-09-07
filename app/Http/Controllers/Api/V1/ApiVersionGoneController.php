<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Catch-all for anything under `/api` that is not a served version.
 *
 * An old build calling the unversioned `/api/events` should learn that it is
 * out of date, not that the server is broken: 410 with `upgrade_required`
 * is a signal the client can act on, a bare 404 is not.
 */
class ApiVersionGoneController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return ApiResponse::error(
            ApiErrorCode::UpgradeRequired,
            'This API version is no longer served. Please update the app.',
            410,
        );
    }
}
