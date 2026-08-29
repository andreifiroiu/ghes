<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Reaction;
use App\Models\Event;
use App\Models\User;
use App\Services\Bookmarks\BookmarkService;
use App\Services\Feedback\ReactionRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EmailReactionController extends Controller
{
    public function __construct(
        private readonly ReactionRecorder $reactions,
        private readonly BookmarkService $bookmarks,
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
            'saved' => $this->bookmarks->add($user, $event->id),
            'hidden' => $this->reactions->record($user, $event->id, Reaction::NotInterested),
            default => $this->reactions->record($user, $event->id, Reaction::from($reaction)),
        };

        return response()->view('emails.reaction-confirmed', [
            'event' => $event,
            'label' => $label,
        ]);
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
