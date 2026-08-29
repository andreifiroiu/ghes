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
