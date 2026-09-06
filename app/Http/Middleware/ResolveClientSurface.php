<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the native app identify itself once, by header, so an activity row
 * it forgets to tag with `from=` still lands on a `mobile_*` surface instead
 * of the ambiguous `api`. The per-screen surface itself is chosen by each
 * endpoint (see ActivitySurface::forApi).
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
}
