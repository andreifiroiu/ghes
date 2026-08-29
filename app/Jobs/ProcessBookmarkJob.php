<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\SerializesProfileWrites;
use App\Models\EventBookmark;
use App\Services\Recommendation\FeedbackProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessBookmarkJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use SerializesProfileWrites;

    public int $tries = 5;

    public function __construct(
        public readonly string $bookmarkId,
        public readonly string $userId,
    ) {
        $this->onQueue('processing');
    }

    public function handle(FeedbackProcessor $processor): void
    {
        $bookmark = EventBookmark::find($this->bookmarkId);

        if ($bookmark === null) {
            // Unsaved before the job ran; the reversal path owns the profile now.
            return;
        }

        $processor->processBookmark($bookmark);
    }

    public function failed(Throwable $e): void
    {
        Log::error('ProcessBookmarkJob: failed permanently', [
            'bookmark_id' => $this->bookmarkId,
            'error' => $e->getMessage(),
        ]);
    }
}
