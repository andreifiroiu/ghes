<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A user's taste signal on an event.
 *
 * Bookmarks are deliberately NOT a reaction — they live in `event_bookmarks`
 * so that saving an event and having an opinion about it are independent.
 */
enum Reaction: string
{
    case Interested = 'interested';
    case NotInterested = 'not_interested';

    /**
     * Romanian display label. Single source of truth for the reaction buttons,
     * the digest email and the email confirmation page.
     */
    public function label(): string
    {
        return match ($this) {
            self::Interested => 'Mă interesează',
            self::NotInterested => 'Nu-i pentru mine',
        };
    }
}
