<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\SerializesProfileWrites;
use App\Models\UserActivityLog;
use App\Services\Recommendation\FeedbackProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turn a recorded implicit action — an outbound click, a calendar download —
 * into a profile signal.
 *
 * Dispatched only for an action taken in an authenticated session by something
 * that is not a bot. Everything else still lands in the activity log for
 * analytics; it just never reaches this job.
 */
class ProcessActivitySignalJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use SerializesProfileWrites;

    public int $tries = 5;

    public function __construct(
        public readonly string $activityLogId,
        public readonly string $userId,
    ) {
        $this->onQueue('processing');
    }

    public function handle(FeedbackProcessor $processor): void
    {
        $log = UserActivityLog::find($this->activityLogId);

        if ($log === null) {
            // Pruned, or the event or user was deleted out from under it.
            // Nothing was applied, so there is nothing to undo.
            return;
        }

        $processor->processImplicitSignal($log);
    }

    public function failed(Throwable $e): void
    {
        Log::error('ProcessActivitySignalJob: failed permanently', [
            'activity_log_id' => $this->activityLogId,
            'error' => $e->getMessage(),
        ]);
    }
}
