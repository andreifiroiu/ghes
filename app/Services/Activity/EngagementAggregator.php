<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Enums\ActivityType;
use App\Models\Event;
use App\Models\UserActivityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Roll recorded activity up into `events.engagement_score`.
 *
 * Ranking needs a behavioural popularity term, and computing one at read time
 * would mean an aggregate over the largest table in the schema for every
 * candidate event on every request. So it is denormalised here on a schedule,
 * which also means the score outlives the activity-log retention window —
 * pruning raw rows does not make the catalogue forget what they taught it.
 */
class EngagementAggregator
{
    /**
     * Recompute every event's engagement score.
     *
     * @return int events left with a non-zero score
     */
    public function recompute(): int
    {
        $scores = $this->scoresByEvent();

        // An empty result set is only trustworthy when there is genuinely
        // nothing to score. If weighted activity exists and the window still
        // returned nothing, the window is misconfigured (a blank env var casts
        // to 0, so `created_at >= now()` matches nothing) or the prune has
        // eaten the rows — and proceeding would zero every score in the
        // catalogue while the command still reported success.
        if ($scores === [] && $this->hasScorableActivity()) {
            Log::error('EngagementAggregator: refusing to zero every score — activity exists but none fell in the window', [
                'window_days' => $this->windowDays(),
                'retention_days' => (int) config('eventpulse.activity.retention_days', 180),
            ]);

            return 0;
        }

        DB::transaction(function () use ($scores): void {
            // Zero everything first. Scores must be able to fall: an event that
            // was popular last month and is untouched this month has to decay
            // out of the ranking, and an incremental update could only ever
            // push scores up.
            Event::query()->where('engagement_score', '!=', 0)->update(['engagement_score' => 0]);

            // Grouped by value so this is at most ~100 statements regardless of
            // how many events scored, rather than one per event.
            foreach ($this->groupIdsByScore($scores) as $score => $eventIds) {
                foreach (array_chunk($eventIds, 1000) as $chunk) {
                    Event::query()->whereIn('id', $chunk)->update(['engagement_score' => $score]);
                }
            }
        });

        return count($scores);
    }

    /**
     * Weighted engagement per event, normalised to 0–100.
     *
     * @return array<string, int>
     */
    private function scoresByEvent(): array
    {
        $windowDays = $this->windowDays();
        $ceiling = max(1, (int) config('eventpulse.activity.engagement_ceiling', 50));

        /** @var list<array{event_id: string, type: string, hits: int}> $rows */
        $rows = UserActivityLog::query()
            ->human()
            ->ofType(ActivityType::weighted())
            ->whereNotNull('event_id')
            ->where('created_at', '>=', now()->subDays($windowDays))
            ->groupBy('event_id', 'type')
            // A bare COUNT aggregate — the grouping keys are columns, not input.
            ->select('event_id', 'type')
            ->selectRaw('count(*) as hits')
            ->get()
            ->map(fn (UserActivityLog $row): array => [
                'event_id' => (string) $row->event_id,
                'type' => $row->type->value,
                'hits' => (int) $row->getAttribute('hits'),
            ])
            ->all();

        $totals = [];

        foreach ($rows as $row) {
            $weight = ActivityType::from($row['type'])->engagementWeight();

            $totals[$row['event_id']] = ($totals[$row['event_id']] ?? 0.0) + ($weight * $row['hits']);
        }

        $scores = [];

        foreach ($totals as $eventId => $total) {
            // Negative totals floor at zero rather than going below events with
            // no signal at all: "people disliked this" and "nobody has seen
            // this" both mean don't promote it, and there is no useful ordering
            // between them.
            $score = (int) round(max(0.0, min(1.0, $total / $ceiling)) * 100);

            if ($score > 0) {
                $scores[$eventId] = $score;
            }
        }

        return $scores;
    }

    /**
     * The rolling window, floored at a day.
     *
     * Guarded the same way the ceiling is: a blank or non-numeric env var casts
     * to 0, and a zero-day window silently matches no activity at all.
     */
    private function windowDays(): int
    {
        return max(1, (int) config('eventpulse.activity.engagement_window_days', 60));
    }

    /**
     * Whether any activity worth scoring exists at all, ignoring the window.
     */
    private function hasScorableActivity(): bool
    {
        return UserActivityLog::query()
            ->human()
            ->ofType(ActivityType::weighted())
            ->whereNotNull('event_id')
            ->exists();
    }

    /**
     * @param  array<string, int>  $scores
     * @return array<int, list<string>>
     */
    private function groupIdsByScore(array $scores): array
    {
        $grouped = [];

        foreach ($scores as $eventId => $score) {
            $grouped[$score][] = $eventId;
        }

        return $grouped;
    }
}
