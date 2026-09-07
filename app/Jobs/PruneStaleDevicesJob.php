<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Device;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Drop devices that have not checked in for a long time: an uninstalled app
 * never says goodbye, and a dead token is a push the service refuses every
 * day for nothing.
 */
class PruneStaleDevicesJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $days = (int) config('eventpulse.push.expo.stale_device_days', 120);

        $deleted = Device::where('last_seen_at', '<', now()->subDays($days))->delete();

        Log::info('Pruned stale devices', ['deleted' => $deleted, 'older_than_days' => $days]);
    }
}
