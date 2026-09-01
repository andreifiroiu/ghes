<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * An action a reader can take on an event straight from the digest email.
 *
 * Wider than Reaction on purpose: `saved` is the bookmark signal rather than a
 * taste signal, and `hidden` is a retired reaction that still appears in links
 * sent before 2026-08-29. Signed digest URLs live 30 days, so that alias has to
 * keep resolving until at least 2026-09-28.
 *
 * This is the shared vocabulary of the email round trip — the link's path
 * segment on the way out, and the `?reacted=` parameter the event page reads on
 * the way back — so both ends agree on what was recorded without either one
 * restating the mapping.
 */
enum DigestAction: string
{
    case Interested = 'interested';
    case NotInterested = 'not_interested';
    case Saved = 'saved';
    case Hidden = 'hidden';

    /**
     * Romanian display label, as used on the confirmation interstitial.
     *
     * The two reaction cases defer to Reaction::label() rather than repeating
     * its strings, so the buttons, the digest and this stay in step.
     */
    public function label(): string
    {
        return match ($this) {
            self::Interested => Reaction::Interested->label(),
            self::NotInterested, self::Hidden => Reaction::NotInterested->label(),
            self::Saved => 'Salvat',
        };
    }

    /**
     * The sentence shown on the event page once the action is recorded.
     *
     * Says what was noted and what it changes, because this is the only
     * feedback a reader gets that their click did anything.
     */
    public function notice(): string
    {
        return match ($this) {
            self::Interested => 'Am notat — te interesează. Îți vom recomanda evenimente similare.',
            self::NotInterested, self::Hidden => 'Am notat — nu e pentru tine. Vom evita evenimente similare.',
            self::Saved => 'Evenimentul a fost salvat în lista ta.',
        };
    }

    /**
     * Resolve the notice for a value taken straight off the query string.
     *
     * Takes `mixed` deliberately, for the reason documented on
     * ActivitySurface::fromRequest(): `?reacted[]=x` yields an array, and a
     * `?string` parameter would raise a TypeError under strict_types and 500 a
     * public route anyone can shape at will. An unrecognised value simply earns
     * no banner.
     */
    public static function noticeFrom(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return self::tryFrom($value)?->notice();
    }
}
