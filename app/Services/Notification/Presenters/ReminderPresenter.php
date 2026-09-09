<?php

declare(strict_types=1);

namespace App\Services\Notification\Presenters;

use App\Enums\ActivitySurface;
use App\Models\Event;
use App\Models\Notification;
use App\Services\Notification\PushPayload;
use App\Services\Notification\ReminderEmailRenderer;
use Carbon\Carbon;
use RuntimeException;

/**
 * A reminder about one event the user bookmarked or marked interested.
 *
 * Everything here resolves the event through resolveCanonical(): a reminder
 * composed 20 minutes ago may name a duplicate that has since been merged
 * away, and the mail must link at the surviving event rather than a row nobody
 * will ever see again.
 */
class ReminderPresenter implements NotificationPresenter
{
    public function __construct(
        private readonly ReminderEmailRenderer $renderer,
    ) {}

    /**
     * A reminder is a claim about the near future, and the near future moves.
     *
     * Between composing and sending, the event can start (a queue backlog), be
     * hidden by an admin, be merged away, or be re-parsed by a scraper into a
     * different time. Only the first of those is caught by the composer, and
     * only at compose time.
     */
    public function isStillRelevant(Notification $notification): bool
    {
        $event = $this->event($notification);

        if ($event === null || $event->is_hidden || $event->starts_at === null) {
            return false;
        }

        $slack = (int) config('eventpulse.reminders.window_minutes', 30);
        $lead = $notification->lead_minutes ?? 0;

        // Still ahead of us, and not so far ahead that the event has since been
        // moved out of the tier this reminder was composed for — "începe peste
        // 3 ore" about something now three days away is worse than silence.
        return $event->starts_at->isFuture()
            && $event->starts_at->lte(now()->addMinutes($lead + $slack));
    }

    public function subject(Notification $notification): string
    {
        $event = $this->event($notification);

        return ReminderEmailRenderer::leadPhrase($notification->lead_minutes ?? 0).': '
            .($event === null ? 'Evenimentul tău' : $event->title);
    }

    public function renderEmail(Notification $notification): string
    {
        $event = $this->event($notification);

        if ($event === null) {
            // isStillRelevant() runs first in the dispatcher and already
            // refuses a reminder without an event, so this is unreachable in
            // practice — but the column is nullable, and rendering a reminder
            // about nothing is worse than failing the job.
            throw new RuntimeException("Reminder {$notification->id} has no event to render");
        }

        return $this->renderer->render($notification, $event);
    }

    public function pushPayload(Notification $notification, string $subject): ?PushPayload
    {
        if ($this->isQuietNow()) {
            return null;
        }

        $event = $this->event($notification);

        if ($event === null) {
            return null;
        }

        return PushPayload::reminder($notification, $event, $subject);
    }

    public function unsubscribeUrl(Notification $notification): ?string
    {
        return ReminderEmailRenderer::unsubscribeUrl($notification);
    }

    public function surface(): ActivitySurface
    {
        return ActivitySurface::Reminder;
    }

    /**
     * Push is suppressed overnight; the email is not.
     *
     * An inbox can wait until morning, a lock screen cannot. The case this
     * exists for is a day-before reminder about a 01:00 after-party, which
     * would otherwise buzz at one in the morning.
     */
    private function isQuietNow(): bool
    {
        /** @var array{from?: int, to?: int} $quiet */
        $quiet = (array) config('eventpulse.reminders.quiet_hours', []);

        $from = (int) ($quiet['from'] ?? 22);
        $to = (int) ($quiet['to'] ?? 8);

        if ($from === $to) {
            return false;
        }

        $city = (string) config('eventpulse.default_city');
        $timezone = (string) config("eventpulse.cities.{$city}.timezone", config('app.timezone'));
        $hour = Carbon::now($timezone)->hour;

        // The window wraps midnight in every sane configuration of it.
        return $from > $to
            ? ($hour >= $from || $hour < $to)
            : ($hour >= $from && $hour < $to);
    }

    private function event(Notification $notification): ?Event
    {
        $notification->loadMissing('event');

        return $notification->event?->resolveCanonical();
    }
}
