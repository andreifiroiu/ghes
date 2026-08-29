<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivitySurface;
use App\Enums\ActivityType;
use App\Enums\Reaction;
use App\Models\Event;
use App\Models\Notification;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Services\Bookmarks\BookmarkService;
use App\Services\Feedback\ReactionRecorder;
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
        $label = $this->labelFor($reaction);

        return response()->view('emails.reaction-confirm', [
            'event' => $event,
            'label' => $label,
            'action' => $request->fullUrl(),
        ]);
    }

    /**
     * Record the action behind a signed email link.
     *
     * The path segment is matched before Reaction::tryFrom because it carries
     * more than the two Reaction cases: `saved` is the bookmark signal, and
     * `hidden` is a retired reaction that still appears in links sent before
     * 2026-08-29 (signed URLs live 30 days, so keep this mapping until at least
     * 2026-09-28).
     */
    public function store(Request $request, User $user, Event $event, string $reaction): Response
    {
        $label = $this->labelFor($reaction);

        match ($reaction) {
            'saved' => $this->bookmarks->add($user, $event->id, ActivitySurface::Digest),
            'hidden' => $this->reactions->record($user, $event->id, Reaction::NotInterested, ActivitySurface::Digest),
            default => $this->reactions->record($user, $event->id, Reaction::from($reaction), ActivitySurface::Digest),
        };

        // Logged on the POST, never on the GET above: a scanner that prefetches
        // the link would otherwise be recorded as a reader who clicked it.
        $this->activity->log(
            ActivityType::EmailClick,
            ActivitySurface::Digest,
            eventId: $event->id,
            user: $user,
            notificationId: $this->notificationIdFrom($request),
            context: ['action' => $reaction],
        );

        return response()->view('emails.reaction-confirmed', [
            'event' => $event,
            'label' => $label,
        ]);
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
     * Romanian label for a link's action, 404ing on anything unrecognised.
     */
    private function labelFor(string $reaction): string
    {
        if ($reaction === 'saved') {
            return 'Salvat';
        }

        if ($reaction === 'hidden') {
            return Reaction::NotInterested->label();
        }

        $enum = Reaction::tryFrom($reaction);

        if ($enum === null) {
            abort(404, 'Invalid reaction type.');
        }

        return $enum->label();
    }
}
