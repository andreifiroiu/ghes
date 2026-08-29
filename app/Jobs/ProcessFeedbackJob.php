<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\UserEventReaction;
use App\Services\Recommendation\FeedbackProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessFeedbackJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly string $reactionId,
        public readonly string $userId,
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
        Log::info('ProcessFeedbackJob: processing reaction', ['reaction_id' => $this->reactionId]);

        $reaction = UserEventReaction::find($this->reactionId);

        if ($reaction === null) {
            // Un-reacted before the job dequeued. Nothing was applied, so there
            // is nothing to undo — this is a normal outcome, not a failure.
            Log::info('ProcessFeedbackJob: reaction removed before processing', [
                'reaction_id' => $this->reactionId,
            ]);

            return;
        }

        $processor->processReaction($reaction);

        Log::info('ProcessFeedbackJob: done', [
            'reaction_id' => $this->reactionId,
            'user_id' => $reaction->user_id,
            'event_id' => $reaction->event_id,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('ProcessFeedbackJob: failed permanently', [
            'reaction_id' => $this->reactionId,
            'error' => $e->getMessage(),
        ]);
    }
}
