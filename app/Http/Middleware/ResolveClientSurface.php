<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ActivitySurface;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the native app identify itself once, by header, so an activity row
 * it forgets to tag with `from=` still lands on a `mobile_*` surface instead
 * of the ambiguous `api`. The per-screen surface itself is chosen by each
 * endpoint (see surfaceFor()).
 */
class ResolveClientSurface
{
    public const HEADER = 'X-Ghes-Client';

    public const CLIENT_MOBILE = 'mobile';

    public const ATTRIBUTE = 'ghes.mobile_client';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(
            self::ATTRIBUTE,
            strtolower(trim((string) $request->header(self::HEADER))) === self::CLIENT_MOBILE,
        );

        return $next($request);
    }

    /**
     * The surface for an API request: an explicit `from` wins; otherwise the
     * endpoint's own mobile screen when the client identified itself as the
     * app, else the ambiguous `api`. Lives here rather than on the enum so
     * the enum stays free of request plumbing.
     */
    public static function surfaceFor(Request $request, ActivitySurface $mobileDefault): ActivitySurface
    {
        $default = $request->attributes->get(self::ATTRIBUTE) === true
            ? $mobileDefault
            : ActivitySurface::Api;

        return ActivitySurface::fromRequest($request->input('from'), $default);
    }
}
