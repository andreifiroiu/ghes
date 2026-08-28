<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Event;
use App\Services\Processing\EventClassifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ClassifyEventJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600];

    public function __construct(
        public readonly string $eventId,
    ) {
        $this->onQueue('ai');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RateLimited('anthropic-api')];
    }

    public function handle(EventClassifier $classifier): void
    {
        Log::info('ClassifyEventJob: classifying', [
            'event_id' => $this->eventId,
            'attempt' => $this->attempts(),
        ]);

        $event = Event::findOrFail($this->eventId);

        $classified = $classifier->classify($event);

        Log::info('ClassifyEventJob: classified', [
            'event_id' => $this->eventId,
            'category' => $classified->category,
            'tags' => $classified->tags,
            'confidence' => $classified->confidence,
        ]);

        // Geocoding runs after classification (see pipeline in SPEC §4.4).
        GeocodeEventJob::dispatch($this->eventId);
    }

    public function failed(Throwable $e): void
    {
        Log::error('ClassifyEventJob: failed permanently', [
            'event_id' => $this->eventId,
            'error' => $e->getMessage(),
        ]);
    }

    public function uniqueId(): string
    {
        return $this->eventId;
    }
}
