<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Services\Recommendation\FeedbackProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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

    /**
     * interest_profile is one JSON blob, so every job that mutates it for a
     * given user must be serialised against the others.
     *
     * shared() is load-bearing: without it WithoutOverlapping mixes the job
     * class into the lock key, so this job would only serialise against other
     * copies of itself and a bookmark job could still interleave with a
     * reaction job on the same profile.
     *
     * expireAfter is equally not optional: without it a worker killed mid-job
     * (deploy, OOM) holds the lock forever and that user's profile silently
     * stops updating. releaseAfter backs off instead of hot-looping, and $tries
     * is generous enough that losing the lock a few times cannot exhaust the
     * attempts and drop the delta on the floor.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->userId))
                ->shared()
                ->releaseAfter(5)
                ->expireAfter(120),
        ];
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
