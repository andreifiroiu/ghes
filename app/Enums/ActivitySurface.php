<?php

declare(strict_types=1);

namespace App\Enums;

use App\Http\Middleware\ResolveClientSurface;
use Illuminate\Http\Request;

/**
 * Where an activity happened.
 *
 * Separate from the type so the same signal can be compared across placements:
 * a click from the digest and a click from the dashboard are the same intent
 * reached by different routes, and the difference is what tells us which
 * surface is worth investing in.
 */
enum ActivitySurface: string
{
    case Dashboard = 'dashboard';
    case EventsIndex = 'events_index';
    case EventDetail = 'event_detail';
    case Digest = 'digest';
    case Push = 'push';
    /** An API caller that did not identify a screen. */
    case Api = 'api';
    case Admin = 'admin';
    // Per screen rather than one `mobile` case: a single case would make
    // mobile the one platform whose funnel cannot be read, and the
    // distinction cannot be backfilled once the rows are written.
    case MobileFeed = 'mobile_feed';
    case MobileBrowse = 'mobile_browse';
    case MobileEventDetail = 'mobile_event_detail';
    case MobileSaved = 'mobile_saved';

    /**
     * Resolve a surface supplied by an untrusted query string (`?from=`).
     *
     * Takes `mixed` deliberately. The caller reads this straight off the query
     * string, where `?from[]=x` yields an array — a `?string` parameter would
     * raise a TypeError under strict_types and 500 a public route that anyone
     * can shape at will. An unrecognised value is likewise not worth a 404 on a
     * redirect the user is waiting on: the click still counts, it just lands
     * under a default.
     */
    public static function fromRequest(mixed $value, self $default = self::EventsIndex): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? $default) : $default;
    }

    /**
     * The surface for an API request: an explicit `from` wins; otherwise the
     * endpoint's own mobile screen when the client identified itself as the
     * app (see ResolveClientSurface), else the ambiguous `api`.
     */
    public static function forApi(Request $request, self $mobileDefault): self
    {
        $default = $request->attributes->get(ResolveClientSurface::ATTRIBUTE) === true
            ? $mobileDefault
            : self::Api;

        return self::fromRequest($request->input('from'), $default);
    }
}
