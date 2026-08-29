<?php

declare(strict_types=1);

namespace App\Services\Activity;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The two things we derive from a request when recording activity: who is
 * loosely doing it, and whether "who" is a person at all.
 *
 * Kept apart from ActivityLogger so the logger holds no request plumbing and
 * tests can pin either answer without faking HTTP.
 */
class RequestFingerprint
{
    public function __construct(
        private readonly Request $request,
    ) {}

    /**
     * A stable, non-identifying handle on the current session.
     *
     * Salted with the app key and hashed, so it is enough to tell one guest's
     * page views apart from another's and useless for anything else. We store
     * no IP address and no raw user agent.
     */
    public function sessionKey(): ?string
    {
        if (! $this->request->hasSession()) {
            return null;
        }

        $id = $this->request->session()->getId();

        if ($id === '') {
            return null;
        }

        return hash('sha256', config('app.key').'|'.$id);
    }

    /**
     * Whether this request looks automated.
     *
     * Mail clients, corporate link scanners and chat-app unfurlers fetch every
     * URL in a message. Without this the digest would report a click-through
     * rate near 100% and the ranking aggregate would promote whichever events
     * happened to be in the most-scanned mailbox. Flagged hits are still
     * recorded — they are real traffic — just excluded from rates and ranking.
     */
    public function isBot(): bool
    {
        return $this->botReason() !== null;
    }

    /**
     * Why this hit was classified as automated, or null if it was not.
     *
     * Returned alongside the flag so a misclassification is auditable in the
     * activity row rather than being a bare boolean nobody can argue with.
     */
    public function botReason(): ?string
    {
        $userAgent = $this->request->userAgent();

        /** @var list<string> $needles */
        $needles = (array) config('eventpulse.activity.bot_user_agents', []);

        if ($userAgent !== null && trim($userAgent) !== '') {
            return $needles !== [] && Str::contains(mb_strtolower($userAgent), $needles)
                ? 'ua_denylist'
                : null;
        }

        // No User-Agent. Every real browser sends one, so for anonymous traffic
        // that is a script — but the authenticated API surface is a different
        // matter: native and server-side clients routinely send none, and a
        // valid session token is better evidence of a person than a header is.
        // Without this carve-out such a client would be flagged forever, its
        // clicks would never reach its profile, and its explorations would keep
        // resolving as misses.
        return $this->request->user() === null ? 'missing_ua' : null;
    }
}
