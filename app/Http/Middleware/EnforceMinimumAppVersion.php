<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ApiErrorCode;
use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The force-upgrade lever for native clients.
 *
 * A build that identifies itself with `X-Ghes-App-Version` below
 * `eventpulse.mobile.min_supported_version` is answered with 426 and the
 * `upgrade_required` code, before any controller runs. An absent header
 * passes — curl, tests and the web app never send one — and so does an
 * unset floor. Cheap to add now; impossible to retrofit once a build that
 * does not send the header is in the stores.
 */
class EnforceMinimumAppVersion
{
    public const HEADER = 'X-Ghes-App-Version';

    /**
     * An optional `v`, one to four dotted numbers, an optional pre-release or
     * build suffix that is ignored. `1.2`, `v1.2.0`, `1.2.0-beta.3+42` all read
     * as 1.2.0; anything else is treated as no header at all.
     */
    private const VERSION_PATTERN = '/^v?(\d+(?:\.\d+){0,3})(?:[-+].*)?$/i';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $minimum = $this->normalise(config('eventpulse.mobile.min_supported_version'));
        $reported = $this->normalise($request->header(self::HEADER));

        if ($minimum !== null && $reported !== null && version_compare($reported, $minimum, '<')) {
            return ApiResponse::error(
                ApiErrorCode::UpgradeRequired,
                'This version of the app is no longer supported. Please update.',
                426,
                ['min_supported_version' => $minimum],
            );
        }

        return $next($request);
    }

    /**
     * Reduce a version string to three-or-more dotted numbers, or null when
     * it cannot be read as one.
     *
     * Both sides go through this so they compare component-wise. Raw
     * version_compare() would not: it ranks `1.2` *below* `1.2.0`, and any
     * unrecognised word (`v1.3.0`, `garbage`) below every number — so a
     * two-component iOS build or a `v`-prefixed one would be locked out with
     * a 426 telling it to update to the version it is already running.
     */
    private function normalise(mixed $value): ?string
    {
        if (! is_string($value) || preg_match(self::VERSION_PATTERN, trim($value), $matches) !== 1) {
            return null;
        }

        $parts = explode('.', $matches[1]);

        while (count($parts) < 3) {
            $parts[] = '0';
        }

        return implode('.', $parts);
    }
}
