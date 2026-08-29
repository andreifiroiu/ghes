<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\SerializesProfileWrites;
use App\Models\UserEventReaction;
use App\Services\Recommendation\FeedbackProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessFeedbackJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use SerializesProfileWrites;

    public int $tries = 5;

    public function __construct(
        public readonly string $reactionId,
        public readonly string $userId,
    ) {
        $this->onQueue('processing');
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
