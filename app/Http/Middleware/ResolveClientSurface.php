<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ActivitySurface;
use App\Models\UserActivityLog;
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
     * Sent by the app on the list endpoints once it measures impressions
     * itself and reports them through `POST /activity`.
     */
    public const IMPRESSIONS_HEADER = 'X-Ghes-Impressions';

    public const IMPRESSIONS_CLIENT = 'client';

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
        return self::surfaceFrom($request, $request->input('from'), $mobileDefault);
    }

    /**
     * The same resolution for a `from` that is not the request's own — each
     * item of a batched activity report names the screen it happened on.
     */
    public static function surfaceFrom(Request $request, mixed $from, ActivitySurface $mobileDefault): ActivitySurface
    {
        $default = $request->attributes->get(self::ATTRIBUTE) === true
            ? $mobileDefault
            : ActivitySurface::Api;

        return ActivitySurface::fromRequest($from, $default);
    }

    /**
     * Whether the app is measuring impressions on-device, so a list endpoint
     * must not also count every card it serves — the two together would
     * double the click-through denominator on mobile.
     *
     * Honoured only for the identified app (nothing else has a tracker to
     * hand the job to), and only once this account has actually reported a
     * client impression within the trust window. The header alone is a
     * promise; a build whose tracker is behind a flag, crashing, or stuck
     * behind a 500 would otherwise switch the server's count off and report
     * nothing in its place, and the mobile click-through rate would divide
     * by zero. If the batches stop, the server-side count resumes by itself
     * when the window lapses. One indexed query per opted-in list request.
     */
    public static function clientRecordsImpressions(Request $request): bool
    {
        if ($request->attributes->get(self::ATTRIBUTE) !== true
            || strtolower(trim((string) $request->header(self::IMPRESSIONS_HEADER))) !== self::IMPRESSIONS_CLIENT) {
            return false;
        }

        $user = $request->user();

        if ($user === null) {
            return false;
        }

        $hours = (int) config('eventpulse.activity.client_impressions_trust_hours', 24);

        // updated_at is when the batch arrived; created_at is the clamped
        // client timestamp and may be a week old on a replayed buffer.
        return UserActivityLog::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->whereNotNull('client_event_id')
            ->where('updated_at', '>=', now()->subHours($hours))
            ->exists();
    }
}
