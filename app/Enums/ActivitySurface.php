<?php

declare(strict_types=1);

namespace App\Enums;

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
    case Api = 'api';
    case Admin = 'admin';

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
}
