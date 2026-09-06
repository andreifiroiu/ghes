<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Device;
use App\Services\Notification\ExpoPushSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Collect the receipts for a batch of Expo push tickets, ~20 minutes after
 * sending, and drop devices the service reports as gone.
 *
 * The ticket → token map rides in the payload, bounded by the 100-message
 * batch, so no tickets table is needed.
 */
class FetchExpoPushReceiptsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  array<string, string>  $ticketsByToken  ticket id => push token
     */
    public function __construct(
        public readonly array $ticketsByToken,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        if ($this->ticketsByToken === []) {
            return;
        }

        $response = Http::acceptJson()
            ->timeout((int) config('eventpulse.push.expo.timeout_seconds', 10))
            ->post((string) config('eventpulse.push.expo.receipts_endpoint'), ['ids' => array_keys($this->ticketsByToken)]);

        if (! $response->successful()) {
            Log::warning('Expo receipts request failed', ['status' => $response->status()]);

            return;
        }

        /** @var array<string, array<string, mixed>> $receipts */
        $receipts = $response->json('data', []);

        foreach ($receipts as $ticketId => $receipt) {
            if (($receipt['status'] ?? null) === 'ok') {
                continue;
            }

            $error = $receipt['details']['error'] ?? null;
            $token = $this->ticketsByToken[$ticketId] ?? null;

            Log::warning('Expo push receipt error', [
                'ticket' => $ticketId,
                'error' => $error,
                'message' => $receipt['message'] ?? null,
            ]);

            if ($error === ExpoPushSender::DEVICE_NOT_REGISTERED && $token !== null) {
                Device::where('push_token', $token)->delete();
            }
        }
    }
}
