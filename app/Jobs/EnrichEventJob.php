<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Event;
use App\Services\Processing\EventEnricher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class EnrichEventJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly string $eventId,
    ) {
        $this->onQueue('enrichment');
    }

    public function handle(EventEnricher $enricher): void
    {
        Log::info('EnrichEventJob: enriching', ['event_id' => $this->eventId]);

        $event = Event::findOrFail($this->eventId);

        $enricher->enrichMetadata($event);

        Log::info('EnrichEventJob: done', ['event_id' => $this->eventId]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('EnrichEventJob: failed permanently', [
            'event_id' => $this->eventId,
            'error' => $e->getMessage(),
        ]);
    }
}
