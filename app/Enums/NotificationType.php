<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What kind of message a notification row is.
 *
 * `event_notifications` was a digest-only table, and several consumers still
 * read it as one: ActivityReporter's digest open rate and FeedbackProcessor's
 * passive decay both scope themselves with `digests()` for that reason. Any
 * new query over the table has to decide which types it means.
 */
enum NotificationType: string
{
    /** The daily/weekly batch of recommendations. */
    case Digest = 'digest';

    /** A single event the user bookmarked or marked interested, about to start. */
    case Reminder = 'reminder';
}
