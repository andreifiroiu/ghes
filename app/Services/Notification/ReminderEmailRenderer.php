<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\Event;
use App\Models\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;

/**
 * Renders a reminder: one event, the time left before it starts, and the same
 * signed reaction round-trip the digest uses.
 */
class ReminderEmailRenderer
{
    /**
     * Lead tiers at or above which the mail offers a calendar file.
     *
     * "Adaugă în calendar" for something starting in three hours is a dead
     * affordance, and CalendarDownload is the heaviest engagement signal the
     * product records — a link scanner prefetching that URL on every reminder
     * would inject it at reminder volume.
     */
    private const CALENDAR_MIN_LEAD_MINUTES = 720;

    /**
     * The signed one-click opt-out for this recipient.
     *
     * Public and static because the same URL has to appear twice — in the
     * footer a person reads, and in the List-Unsubscribe header a mail provider
     * reads — and the two must not be able to drift apart.
     *
     * Never expires. An unsubscribe link that has timed out is worse than no
     * link: the reader clicks it, is told it is invalid, and reports the mail
     * as spam instead.
     */
    /**
     * How long is left, phrased the way a person would say it in Romanian.
     *
     * Lives here rather than on the presenter because it is the mail's own
     * wording, and the presenter reuses it for the subject line — the subject
     * and the header must not be able to disagree about how far off the event
     * is.
     */
    public static function leadPhrase(int $minutes): string
    {
        return match (true) {
            $minutes >= 1440 => 'Mâine',
            $minutes >= 120 => 'Peste '.intdiv($minutes, 60).' ore',
            $minutes >= 60 => 'Într-o oră',
            default => 'În curând',
        };
    }

    public static function unsubscribeUrl(Notification $notification): string
    {
        return URL::signedRoute('unsubscribe.reminders', ['user' => $notification->user_id]);
    }

    public function render(Notification $notification, Event $event): string
    {
        $notification->loadMissing('user');
        $user = $notification->user;

        $expiry = now()->addDays(30);

        $params = fn (string $action): array => [
            'user' => $user->id,
            'event' => $event->id,
            'reaction' => $action,
            'n' => $notification->id,
        ];

        return View::make('emails.reminder', [
            'user' => $user,
            'event' => $event,
            'subject' => $notification->subject,
            'leadPhrase' => self::leadPhrase($notification->lead_minutes ?? 0),
            'clickUrl' => route('events.show', [
                'event' => $event->id,
                'from' => 'reminder',
                'n' => $notification->id,
            ]),
            'calendarUrl' => ($notification->lead_minutes ?? 0) >= self::CALENDAR_MIN_LEAD_MINUTES
                ? route('events.calendar', ['event' => $event->id, 'from' => 'reminder', 'n' => $notification->id])
                : null,
            'notInterestedUrl' => URL::temporarySignedRoute('reactions.email', $expiry, $params('not_interested')),
            'unsubscribeUrl' => self::unsubscribeUrl($notification),
            'openPixelUrl' => URL::signedRoute('notifications.open', ['notification' => $notification->id]),
        ])->render();
    }
}
