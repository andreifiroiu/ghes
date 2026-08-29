<?php

declare(strict_types=1);

namespace App\Jobs;

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

    public int $tries = 2;

    public function __construct(
        public readonly string $reactionId,
    ) {
        $this->onQueue('processing');
    }

    public function handle(FeedbackProcessor $processor): void
    {
        Log::info('ProcessFeedbackJob: processing reaction', ['reaction_id' => $this->reactionId]);

        $reaction = UserEventReaction::findOrFail($this->reactionId);

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
