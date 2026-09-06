<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Device;
use App\Services\Notification\ExpoPushSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

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
     * @var list<int>
     */
    public array $backoff = [120, 600, 1800];

    /**
     * @param  array<string, string>  $ticketsByToken  ticket id => push token
     */
    public function __construct(
        public readonly array $ticketsByToken,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(ExpoPushSender $expo): void
    {
        if ($this->ticketsByToken === []) {
            return;
        }

        try {
            $response = $expo->client()->post(
                (string) config('eventpulse.push.expo.receipts_endpoint'),
                ['ids' => array_keys($this->ticketsByToken)],
            );
        } catch (ConnectionException $e) {
            throw new RuntimeException('Expo receipts endpoint unreachable: '.$e->getMessage(), 0, $e);
        }

        // Rate limited or down: worth retrying. Any other rejection is about
        // this request and will not change.
        if ($response->status() === 429 || $response->serverError()) {
            throw new RuntimeException('Expo receipts endpoint answered '.$response->status());
        }

        if (! $response->successful()) {
            Log::error('Expo receipts request rejected', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
                'tickets' => array_keys($this->ticketsByToken),
            ]);

            return;
        }

        $receipts = $response->json('data');

        if (! is_array($receipts)) {
            Log::error('Expo receipts response carried no data', ['body' => mb_substr($response->body(), 0, 500)]);

            return;
        }

        foreach ($receipts as $ticketId => $receipt) {
            if (! is_array($receipt) || ($receipt['status'] ?? null) === 'ok') {
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
