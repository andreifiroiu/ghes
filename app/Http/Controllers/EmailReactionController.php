<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivitySurface;
use App\Enums\ActivityType;
use App\Enums\DigestAction;
use App\Enums\Reaction;
use App\Models\Event;
use App\Models\Notification;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Services\Bookmarks\BookmarkService;
use App\Services\Feedback\ReactionRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class EmailReactionController extends Controller
{
    public function __construct(
        private readonly ReactionRecorder $reactions,
        private readonly BookmarkService $bookmarks,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Render the confirmation page for a signed email reaction link.
     *
     * This GET deliberately writes nothing. Mail clients and corporate link
     * scanners fetch every URL in a message, so a state-mutating GET would
     * register reactions and bookmarks the user never clicked. The page
     * auto-submits a POST to the same (still signed) URI instead.
     */
    public function show(Request $request, User $user, Event $event, string $reaction): Response
    {
        return response()->view('emails.reaction-confirm', [
            'event' => $event,
            'label' => $this->actionFor($reaction)->label(),
            'action' => $request->fullUrl(),
        ]);
    }

    /**
     * Record the action behind a signed email link, then hand the reader the
     * event itself.
     *
     * The redirect replaces a dead-end confirmation card whose only exit was
     * the dashboard — a page behind auth, which this reader usually is not:
     * identity here comes from the URL signature precisely because mail
     * webviews drop the session cookie. `?reacted=` carries the confirmation
     * instead of a session flash for the same reason: no cookie, no flash.
     */
    public function store(Request $request, User $user, Event $event, string $reaction): RedirectResponse
    {
        $action = $this->actionFor($reaction);

        match ($action) {
            DigestAction::Saved => $this->bookmarks->add($user, $event->id, ActivitySurface::Digest),
            DigestAction::NotInterested, DigestAction::Hidden => $this->reactions->record($user, $event->id, Reaction::NotInterested, ActivitySurface::Digest),
            DigestAction::Interested => $this->reactions->record($user, $event->id, Reaction::Interested, ActivitySurface::Digest),
        };

        $notificationId = $this->notificationIdFrom($request);

        // Logged on the POST, never on the GET above: a scanner that prefetches
        // the link would otherwise be recorded as a reader who clicked it.
        $this->activity->log(
            ActivityType::EmailClick,
            ActivitySurface::Digest,
            eventId: $event->id,
            user: $user,
            notificationId: $notificationId,
            context: ['action' => $reaction],
        );

        // `from` and `n` are what the event page needs to attribute the view it
        // always logs to this digest, exactly as its "Vezi detalii" link does.
        // The resolved id is passed rather than the raw `?n=`, so a junk value
        // is dropped here instead of travelling on.
        return redirect()->route('events.show', array_filter([
            'event' => $event->id,
            'from' => ActivitySurface::Digest->value,
            'n' => $notificationId,
            'reacted' => $action->value,
        ]));
    }

    /**
     * The digest this link came from, if `n` names a real one.
     *
     * Two separate reasons to resolve rather than trust: notification_id is a
     * foreign key, so a stale id from an expired digest would fail the insert
     * and cost us the whole row; and the primary key is a Postgres `uuid`, so
     * looking it up with arbitrary text raises a QueryException instead of
     * returning nothing.
     */
    private function notificationIdFrom(Request $request): ?string
    {
        $id = $request->query('n');

        if (! is_string($id) || ! Str::isUuid($id)) {
            return null;
        }

        return Notification::whereKey($id)->value('id');
    }

    /**
     * The action a link's path segment names, 404ing on anything unrecognised.
     */
    private function actionFor(string $reaction): DigestAction
    {
        $action = DigestAction::tryFrom($reaction);

        if ($action === null) {
            abort(404, 'Invalid reaction type.');
        }

        return $action;
    }
}
