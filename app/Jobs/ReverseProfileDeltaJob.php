<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\SerializesProfileWrites;
use App\Models\User;
use App\Services\Recommendation\FeedbackProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Undo the profile contribution of a signal the user has removed.
 *
 * Shared by un-reacting and un-saving. The delta map travels in the payload
 * rather than being read back from the row, because the row is deleted as soon
 * as the user removes the signal.
 */
class ReverseProfileDeltaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use SerializesProfileWrites;

    public int $tries = 5;

    /**
     * @param  array<string, float>  $appliedDeltas
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $eventId,
        public readonly array $appliedDeltas,
    ) {
        $this->onQueue('processing');
    }

    public function handle(FeedbackProcessor $processor): void
    {
        $user = User::find($this->userId);

        if ($user === null) {
            return;
        }

        $processor->reverseSignal($user, $this->eventId, $this->appliedDeltas);
    }

    public function failed(Throwable $e): void
    {
        Log::error('ReverseProfileDeltaJob: failed permanently', [
            'user_id' => $this->userId,
            'event_id' => $this->eventId,
            'error' => $e->getMessage(),
        ]);
    }
}
