<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class CleanupExpiredEventsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        Log::info('CleanupExpiredEventsJob: starting cleanup');

        $deleted = Event::query()
            ->where('starts_at', '<', now()->subDays(90))
            ->whereDoesntHave('reactions')
            ->delete();

        Log::info('CleanupExpiredEventsJob: deleted expired events', ['deleted' => $deleted]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('CleanupExpiredEventsJob: failed permanently', ['error' => $e->getMessage()]);
    }
}
