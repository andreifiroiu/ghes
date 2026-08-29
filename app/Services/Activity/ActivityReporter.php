<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Enums\ActivityType;
use App\Enums\NotificationChannel;
use App\Models\Event;
use App\Models\Notification;
use App\Models\UserActivityLog;
use Illuminate\Support\Carbon;

/**
 * Read-side aggregates over the activity log.
 *
 * Every figure here excludes bot traffic. A report that counted mail-scanner
 * prefetches would show a click-through rate near 100% and be worse than no
 * report at all, because someone would believe it.
 */
class ActivityReporter
{
    /**
     * Ceiling on distinct search terms pulled back before case folding.
     *
     * A guard against a pathological long tail, not a product limit — the
     * report only ever shows the top handful.
     */
    private const MAX_DISTINCT_TERMS = 1000;

    /**
     * @return array{
     *     window_days: int,
     *     counts: array<string, int>,
     *     click_through_rate: float,
     *     digest: array{sent: int, opened: int, open_rate: float, clicks: int},
     *     top_events: list<array{id: string, title: string, clicks: int, impressions: int}>,
     *     top_searches: list<array{term: string, hits: int}>,
     * }
     */
    public function summary(int $windowDays = 30): array
    {
        $since = now()->subDays($windowDays);

        $counts = $this->countsByType($since);
        $impressions = $counts[ActivityType::EventImpression->value] ?? 0;
        $clicks = $counts[ActivityType::EventClick->value] ?? 0;

        return [
            'window_days' => $windowDays,
            'counts' => $counts,
            'click_through_rate' => $impressions > 0 ? round($clicks / $impressions, 4) : 0.0,
            'digest' => $this->digestStats($since),
            'top_events' => $this->topEvents($since),
            'top_searches' => $this->topSearches($since),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function countsByType(Carbon $since): array
    {
        $counts = UserActivityLog::query()
            ->human()
            ->where('created_at', '>=', $since)
            ->groupBy('type')
            ->select('type')
            ->selectRaw('count(*) as hits')
            ->pluck('hits', 'type');

        // Every type present, so a zero reads as a real zero rather than a gap
        // the UI has to guess about.
        $result = [];

        foreach (ActivityType::cases() as $type) {
            $result[$type->value] = (int) ($counts[$type->value] ?? 0);
        }

        return $result;
    }

    /**
     * Digest reach.
     *
     * The open-rate denominator counts only digests that could ever report an
     * open. A push-only user gets a notification row with sent_at set and no
     * email to put a pixel in, so including them would make the rate read
     * permanently depressed for a reason that has nothing to do with the email.
     *
     * @return array{sent: int, opened: int, open_rate: float, clicks: int}
     */
    private function digestStats(Carbon $since): array
    {
        $emailable = Notification::query()
            ->whereNotNull('sent_at')
            ->where('sent_at', '>=', $since)
            ->whereIn('channel', [NotificationChannel::Email->value, NotificationChannel::Both->value]);

        $sent = (clone $emailable)->count();
        $opened = (clone $emailable)->whereNotNull('opened_at')->count();

        $clicks = UserActivityLog::query()
            ->human()
            ->ofType([ActivityType::EmailClick, ActivityType::EventClick])
            ->whereNotNull('notification_id')
            ->where('created_at', '>=', $since)
            ->count();

        return [
            'sent' => $sent,
            'opened' => $opened,
            'open_rate' => $sent > 0 ? round($opened / $sent, 4) : 0.0,
            'clicks' => $clicks,
        ];
    }

    /**
     * The events people actually clicked through on, with the impressions that
     * earned those clicks.
     *
     * @return list<array{id: string, title: string, clicks: int, impressions: int}>
     */
    private function topEvents(Carbon $since, int $limit = 15): array
    {
        $clicks = UserActivityLog::query()
            ->human()
            ->ofType(ActivityType::EventClick)
            ->whereNotNull('event_id')
            ->where('created_at', '>=', $since)
            ->groupBy('event_id')
            ->select('event_id')
            ->selectRaw('count(*) as hits')
            ->orderByDesc('hits')
            ->limit($limit)
            ->pluck('hits', 'event_id');

        if ($clicks->isEmpty()) {
            return [];
        }

        $eventIds = $clicks->keys()->all();

        $impressions = UserActivityLog::query()
            ->human()
            ->ofType(ActivityType::EventImpression)
            ->whereIn('event_id', $eventIds)
            ->where('created_at', '>=', $since)
            ->groupBy('event_id')
            ->select('event_id')
            ->selectRaw('count(*) as hits')
            ->pluck('hits', 'event_id');

        $titles = Event::whereIn('id', $eventIds)->pluck('title', 'id');

        return $clicks->map(fn (int $hits, string $eventId): array => [
            'id' => $eventId,
            'title' => (string) ($titles[$eventId] ?? 'Eveniment șters'),
            'clicks' => $hits,
            'impressions' => (int) ($impressions[$eventId] ?? 0),
        ])->values()->all();
    }

    /**
     * What people typed into the search box, most frequent first.
     *
     * Filter-only browses are excluded: a category tab is a click, not a
     * question, and mixing the two buries the actual search terms.
     *
     * @return list<array{term: string, hits: int}>
     */
    private function topSearches(Carbon $since, int $limit = 15): array
    {
        // Grouped in SQL, not in PHP. A Search row is written on every filtered
        // browse by every visitor, guests included, so hydrating the window's
        // whole result set — 90 days is offered in the UI — would put an
        // unbounded number of jsonb blobs in memory on a synchronous admin
        // request. Grouping first bounds this by distinct terms instead.
        $counts = UserActivityLog::query()
            ->human()
            ->ofType(ActivityType::Search)
            ->where('created_at', '>=', $since)
            ->whereNotNull('context->filters->search')
            ->groupBy('context->filters->search')
            ->selectRaw('count(*) as hits')
            ->addSelect('context->filters->search as term')
            ->orderByDesc('hits')
            ->limit(self::MAX_DISTINCT_TERMS)
            ->pluck('hits', 'term');

        // Case folding stays in PHP: lower() over a JSON path differs per
        // driver, and this now runs over distinct terms rather than every row.
        $folded = [];

        foreach ($counts as $term => $hits) {
            $key = mb_strtolower(trim((string) $term));

            if ($key === '') {
                continue;
            }

            $folded[$key] = ($folded[$key] ?? 0) + (int) $hits;
        }

        arsort($folded);

        $top = array_slice($folded, 0, $limit, true);

        return array_map(
            fn (string $term, int $hits): array => ['term' => $term, 'hits' => $hits],
            array_keys($top),
            array_values($top),
        );
    }
}
