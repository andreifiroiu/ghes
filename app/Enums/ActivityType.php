<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A single recorded user action.
 *
 * Deliberately flat rather than a type + value pair: every analytics query is a
 * plain `GROUP BY type`, and the ranking aggregate is a `CASE` over one column.
 */
enum ActivityType: string
{
    case EventImpression = 'event_impression';
    case EventView = 'event_view';
    case EventClick = 'event_click';
    case CalendarDownload = 'calendar_download';
    case ReactionInterested = 'reaction_interested';
    case ReactionNotInterested = 'reaction_not_interested';
    case ReactionCleared = 'reaction_cleared';
    case BookmarkAdded = 'bookmark_added';
    case BookmarkRemoved = 'bookmark_removed';
    case Search = 'search';
    case EmailOpen = 'email_open';
    case EmailClick = 'email_click';

    /**
     * Contribution to an event's behavioural popularity.
     *
     * The weights live here rather than in the aggregate query so that adding a
     * case forces a decision about how it ranks. Types that say nothing about
     * *this* event's appeal — an impression the user never looked at, a search,
     * an email open — weigh nothing. A negative weight lets a disliked event
     * fall below one nobody has touched.
     */
    public function engagementWeight(): float
    {
        return match ($this) {
            // A calendar entry commits a slot in someone's week. It is the
            // strongest statement of intent the product can observe — a
            // bookmark is one reversible tap, this is a plan.
            self::CalendarDownload => 5.0,
            self::BookmarkAdded => 5.0,
            self::ReactionInterested => 4.0,
            self::EventClick, self::EmailClick => 3.0,
            self::EventView => 1.0,
            self::ReactionNotInterested => -4.0,
            self::BookmarkRemoved => -2.0,
            self::ReactionCleared => -1.0,
            self::EventImpression, self::Search, self::EmailOpen => 0.0,
        };
    }

    /**
     * The `feedback.deltas` signal this type feeds into the interest profile,
     * or null for a type that only ever informs analytics and ranking.
     *
     * Implicit signals only: reactions and bookmarks reach the profile through
     * their own rows, which carry a reversal ledger the user can undo. These
     * cannot be taken back, so they are weaker by design and scored once per
     * (user, event) — see FeedbackProcessor::processImplicitSignal().
     *
     * The name doubles as the value written to `discovery_logs.outcome`, so a
     * resolved exploration says which signal resolved it.
     */
    public function implicitSignal(): ?string
    {
        return match ($this) {
            self::EventClick => 'clicked',
            self::CalendarDownload => 'calendar',
            default => null,
        };
    }

    /**
     * Types that can move a profile on their own.
     *
     * @return list<self>
     */
    public static function implicit(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $type): bool => $type->implicitSignal() !== null,
        ));
    }

    /**
     * Types that carry a weight, so the aggregate can skip the rest outright.
     *
     * @return list<self>
     */
    public static function weighted(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $type): bool => $type->engagementWeight() !== 0.0,
        ));
    }

    /**
     * The reaction-mirroring type for a taste signal, used to keep the activity
     * log in step with `user_event_reactions` without duplicating the mapping.
     */
    public static function forReaction(Reaction $reaction): self
    {
        return match ($reaction) {
            Reaction::Interested => self::ReactionInterested,
            Reaction::NotInterested => self::ReactionNotInterested,
        };
    }
}
