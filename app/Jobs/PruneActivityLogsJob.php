<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\UserActivityLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Drop activity rows past the retention window.
 *
 * Raw activity is the only unbounded growth this feature introduces — every
 * page view of every visitor lands in one table. What we learned from those
 * rows survives them: engagement_score is denormalised onto events, and the
 * profile deltas a click produced are already in the user's interest_profile.
 */
class PruneActivityLogsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $days = (int) config('eventpulse.activity.retention_days', 180);

        // A blank or non-numeric EVENTPULSE_ACTIVITY_RETENTION_DAYS casts to 0,
        // which would make the cutoff *now* and delete the entire activity log
        // — including rows written seconds ago. config()'s default cannot save
        // us: the key exists, it is its value that is bad. The engagement
        // aggregate would then zero every score on its next run and the first
        // visible symptom would be a ranking regression days later, with the
        // evidence already gone. Refuse rather than guess a retention policy.
        if ($days < 1) {
            Log::error('PruneActivityLogsJob: refusing to prune, retention_days is not a positive number', [
                'configured' => config('eventpulse.activity.retention_days'),
            ]);

            return;
        }

        $cutoff = now()->subDays($days);

        // Chunked, and by explicit id rather than DELETE ... LIMIT, which is not
        // portable. A single unbounded DELETE over months of activity would hold
        // a long transaction against a table every request in the app is
        // inserting into.
        $deleted = 0;

        do {
            $ids = UserActivityLog::query()
                ->where('created_at', '<', $cutoff)
                ->limit(5000)
                ->pluck('id')
                ->all();

            $deleted += $ids === [] ? 0 : UserActivityLog::whereIn('id', $ids)->delete();
        } while ($ids !== []);

        Log::info('PruneActivityLogsJob: pruned activity logs', [
            'deleted' => $deleted,
            'cutoff' => $cutoff->toDateTimeString(),
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('PruneActivityLogsJob: failed permanently', ['error' => $e->getMessage()]);
    }
}
