<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DTOs\RawEvent;
use App\Services\Processing\EventPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessRawEventJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly RawEvent $rawEvent,
    ) {
        $this->onQueue('processing');
    }

    public function handle(EventPipeline $pipeline): void
    {
        Log::info('ProcessRawEventJob: processing raw event', [
            'title' => $this->rawEvent->title,
            'source' => $this->rawEvent->source,
        ]);

        $event = $pipeline->process($this->rawEvent);

        Log::info('ProcessRawEventJob: done', [
            'title' => $this->rawEvent->title,
            'result' => $event !== null ? 'saved' : 'skipped_duplicate',
            'event_id' => $event?->id,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('ProcessRawEventJob: failed permanently', [
            'title' => $this->rawEvent->title,
            'source' => $this->rawEvent->source,
            'error' => $e->getMessage(),
        ]);
    }
}
