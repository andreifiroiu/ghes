<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivitySurface;
use App\Enums\ActivityType;
use App\Jobs\ProcessActivitySignalJob;
use App\Models\Event;
use App\Models\Notification;
use App\Services\Activity\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ActivityController extends Controller
{
    /**
     * A 1×1 transparent GIF, inline so open tracking needs no asset pipeline.
     */
    private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function __construct(
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Record an outbound click and forward to the event's source.
     *
     * The destination is read from the event's own stored `source_url` and
     * never from the request. A redirector that forwards to a URL a caller
     * supplies is an open redirect, and this one is public and unauthenticated
     * — it would be phishing infrastructure hosted on our domain.
     */
    public function redirect(Request $request, Event $event): RedirectResponse
    {
        $event = $event->resolveCanonical();

        abort_if($event->is_hidden, 404);

        $destination = $this->destinationFor($request, $event);

        abort_if($destination === null, 404);

        $notification = $this->notificationFrom($request);
        $sessionUser = $request->user();

        // Attribute an unauthenticated digest click to the recipient the digest
        // was addressed to, so per-user digest reporting works when someone
        // reads their mail signed out. This is attribution only: the profile
        // nudge below still requires a real session, because this route is a
        // GET that every mail scanner in the world will fetch.
        $user = $sessionUser ?? $notification?->user;

        $log = $this->activity->log(
            ActivityType::EventClick,
            ActivitySurface::fromRequest($request->query('from')),
            eventId: $event->id,
            user: $user,
            notificationId: $notification?->id,
            context: [
                'authenticated' => $sessionUser !== null,
                'source' => $destination['source'],
            ],
        );

        if ($log !== null && $sessionUser !== null && ! $log->is_bot) {
            ProcessActivitySignalJob::dispatch($log->id, $sessionUser->id);
        }

        // 302, not 301: a permanent redirect would be cached by the browser and
        // every click after the first would never reach us again.
        return redirect()->away($destination['url'], 302);
    }

    /**
     * Where this click should land, and which provider it credits.
     *
     * A popular event is listed by several providers, and the detail page
     * offers one button each, so `?s=` says which was chosen. It *selects*
     * among the event's own stored URLs rather than supplying one — the value
     * is matched against this event's `event_sources` rows and ignored if it
     * does not name one of them. Anything else would turn a public redirect
     * into an open one.
     *
     * @return array{url: string, source: string}|null
     */
    private function destinationFor(Request $request, Event $event): ?array
    {
        $requested = $request->query('s');

        if (is_string($requested) && $requested !== '') {
            $match = $event->sources()
                ->where('source', $requested)
                ->whereNotNull('source_url')
                ->first();

            if ($match !== null && $match->source_url !== '') {
                return ['url' => $match->source_url, 'source' => $match->source];
            }
        }

        return $event->source_url === ''
            ? null
            : ['url' => $event->source_url, 'source' => $event->source];
    }

    /**
     * Open-tracking pixel for a digest.
     *
     * Signed rather than merely unguessable so the timestamp cannot be forged
     * by anyone who scrapes a forwarded email. `opened_at` records the *first*
     * open only — mail clients refetch images on every reopen, and overwriting
     * would turn "when did this land" into "when was it last looked at".
     */
    public function open(Request $request, Notification $notification): Response|BinaryFileResponse
    {
        $log = $this->activity->log(
            ActivityType::EmailOpen,
            ActivitySurface::Digest,
            user: $notification->user,
            notificationId: $notification->id,
        );

        // Only a human open stamps the column. Security scanners fetch every
        // URL in a message the moment it is delivered, and letting one set
        // opened_at would peg the open rate near 100% for a reason that has
        // nothing to do with anyone reading it. Mail image proxies are not in
        // that category and are not flagged — see activity.bot_user_agents.
        if ($notification->opened_at === null && $log !== null && ! $log->is_bot) {
            $notification->update(['opened_at' => now()]);
        }

        return response(base64_decode(self::PIXEL, true), 200, [
            'Content-Type' => 'image/gif',
            // Without this the client caches the pixel and a reopen is invisible.
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * The digest a tracked link came from, if the `n` parameter names a real one.
     *
     * The UUID shape is checked before the lookup, not after: the primary key is
     * a Postgres `uuid`, so querying it with arbitrary text raises a
     * QueryException rather than returning nothing — and this route is public,
     * so `?n=x` would be a 500 anyone could trigger. Unresolvable ids are then
     * ignored rather than fatal: the click is what matters, and the user is
     * waiting on the redirect.
     */
    private function notificationFrom(Request $request): ?Notification
    {
        $id = $request->query('n');

        if (! is_string($id) || ! Str::isUuid($id)) {
            return null;
        }

        return Notification::with('user')->find($id);
    }
}
