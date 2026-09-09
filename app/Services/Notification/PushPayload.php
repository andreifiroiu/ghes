<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Enums\PushPayloadType;
use App\Models\Event;
use App\Models\Notification;

/**
 * One notification, rendered once and adapted per channel: web push reads
 * `url`, the native app reads `deep_link` and the rest of `data()`. Both are
 * derived from the same object so the two channels cannot say different
 * things about where a tap should land.
 */
final readonly class PushPayload
{
    public function __construct(
        public PushPayloadType $type,
        public string $title,
        public string $body,
        public string $url,
        public string $deepLink,
        public ?string $notificationId = null,
        public ?string $eventId = null,
    ) {}

    public static function digest(Notification $notification, string $title, int $eventCount): self
    {
        return new self(
            type: PushPayloadType::Digest,
            title: $title,
            body: "Ai {$eventCount} evenimente noi recomandate pentru tine.",
            url: route('dashboard'),
            deepLink: self::deepLink('digest/'.$notification->id),
            notificationId: $notification->id,
        );
    }

    /**
     * A reminder about one event.
     *
     * The deep link points at the event rather than at the notification: a
     * reminder is only ever about one thing, and `ghes://events/{id}` is a
     * route the shipped client already handles from cold start, background and
     * foreground.
     */
    public static function reminder(Notification $notification, Event $event, string $title): self
    {
        $startsAt = $event->starts_at;

        return new self(
            type: PushPayloadType::Reminder,
            title: $title,
            body: $startsAt === null
                ? ($event->venue ?? 'Vezi detaliile evenimentului.')
                : trim($startsAt->format('H:i').($event->venue === null ? '' : ' · '.$event->venue)),
            url: route('events.show', ['event' => $event->id, 'from' => 'reminder', 'n' => $notification->id]),
            deepLink: self::deepLink('events/'.$event->id),
            notificationId: $notification->id,
            eventId: $event->id,
        );
    }

    /**
     * The `data` block the native client switches on.
     *
     * @return array{type: string, deep_link: string, notification_id: string|null, event_id: string|null}
     */
    public function data(): array
    {
        return [
            'type' => $this->type->value,
            'deep_link' => $this->deepLink,
            'notification_id' => $this->notificationId,
            'event_id' => $this->eventId,
        ];
    }

    private static function deepLink(string $path): string
    {
        return config('eventpulse.mobile.scheme', 'ghes').'://'.$path;
    }
}
