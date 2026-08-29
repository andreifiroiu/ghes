<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\Event;
use App\Models\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;

class EmailRenderer
{
    /**
     * Render a notification into an HTML email string.
     *
     * Loads events, generates signed reaction URLs and tracked event links, and
     * renders the Blade template.
     */
    public function render(Notification $notification): string
    {
        $notification->loadMissing('user');
        $user = $notification->user;

        $recommendedEvents = Event::whereIn('id', $notification->event_ids ?? [])->get();
        $discoveryEvents = Event::whereIn('id', $notification->discovery_event_ids ?? [])->get();

        // Attach signed reaction URLs to each event
        $expiry = now()->addDays(30);

        $attachUrls = function (Event $event) use ($user, $notification, $expiry): array {
            // `n` rides along inside the signature, so a reaction can be traced
            // back to the digest that prompted it without becoming forgeable.
            $params = fn (string $reaction): array => [
                'user' => $user->id,
                'event' => $event->id,
                'reaction' => $reaction,
                'n' => $notification->id,
            ];

            return [
                'event' => $event,
                'reaction_urls' => [
                    'interested' => URL::temporarySignedRoute('reactions.email', $expiry, $params('interested')),
                    'not_interested' => URL::temporarySignedRoute('reactions.email', $expiry, $params('not_interested')),
                    'saved' => URL::temporarySignedRoute('reactions.email', $expiry, $params('saved')),
                ],
                // The event's own page, not a jump straight to the ticket site:
                // it carries the map, the full description and every provider
                // selling tickets, and it is where a card click lands in the app
                // — a digest that skipped it would be the one inconsistent
                // surface. Unsigned on purpose, since it is public and carries
                // no authority; `from` and `n` only label the resulting view.
                'click_url' => route('events.show', [
                    'event' => $event->id,
                    'from' => 'digest',
                    'n' => $notification->id,
                ]),
            ];
        };

        $recommended = $recommendedEvents->map($attachUrls)->toArray();
        $discovery = $discoveryEvents->map($attachUrls)->toArray();

        return View::make('emails.digest', [
            'user' => $user,
            'recommendedEvents' => $recommended,
            'discoveryEvents' => $discovery,
            'subject' => $notification->subject,
            'openPixelUrl' => URL::signedRoute('notifications.open', ['notification' => $notification->id]),
        ])->render();
    }
}
