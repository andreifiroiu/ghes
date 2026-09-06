<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Contracts\PushChannel;
use App\Jobs\FetchExpoPushReceiptsJob;
use App\Models\Device;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers through the Expo Push Service.
 *
 * One HTTPS endpoint and one credential instead of an APNs key plus an FCM
 * service account. Tickets come back synchronously per message; receipts
 * (which is where `DeviceNotRegistered` usually shows up) arrive later and
 * are fetched by a delayed job. Never throws — see PushChannel.
 */
class ExpoPushSender implements PushChannel
{
    public const DEVICE_NOT_REGISTERED = 'DeviceNotRegistered';

    public function send(User $user, PushPayload $payload): int
    {
        if (! $this->isEnabled()) {
            return 0;
        }

        $devices = $user->devices()->get();

        if ($devices->isEmpty()) {
            return 0;
        }

        $accepted = 0;

        try {
            foreach ($devices->chunk((int) config('eventpulse.push.expo.batch_size', 100)) as $chunk) {
                $accepted += $this->sendBatch($chunk->values(), $payload);
            }
        } catch (Throwable $e) {
            // A push failure must never reach the dispatcher: sent_at is set
            // after this branch, and an escaping exception would re-send the
            // email on retry.
            Log::error('Expo push failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        return $accepted;
    }

    /**
     * @param  Collection<int, Device>  $devices
     */
    private function sendBatch($devices, PushPayload $payload): int
    {
        $messages = $devices->map(fn (Device $device): array => [
            'to' => $device->push_token,
            'title' => $payload->title,
            'body' => $payload->body,
            'sound' => 'default',
            'channelId' => $payload->type->value,
            'priority' => 'normal',
            'data' => $payload->data(),
        ])->all();

        $response = $this->client()->post((string) config('eventpulse.push.expo.endpoint'), $messages);

        if (! $response->successful()) {
            Log::warning('Expo push rejected the batch', ['status' => $response->status(), 'body' => $response->body()]);

            return 0;
        }

        /** @var array<int, array<string, mixed>> $tickets */
        $tickets = $response->json('data', []);

        $accepted = 0;
        $ticketsByToken = [];

        foreach ($devices as $index => $device) {
            $ticket = $tickets[$index] ?? null;

            if (! is_array($ticket)) {
                continue;
            }

            if (($ticket['status'] ?? null) === 'ok' && isset($ticket['id'])) {
                $ticketsByToken[(string) $ticket['id']] = $device->push_token;
                $accepted++;

                continue;
            }

            $this->handleError($device, $ticket);
        }

        if ($ticketsByToken !== []) {
            FetchExpoPushReceiptsJob::dispatch($ticketsByToken)
                ->delay(now()->addMinutes((int) config('eventpulse.push.expo.receipt_delay_minutes', 20)));
        }

        return $accepted;
    }

    /**
     * @param  array<string, mixed>  $ticket
     */
    private function handleError(Device $device, array $ticket): void
    {
        $error = $ticket['details']['error'] ?? null;

        Log::warning('Expo push ticket error', [
            'device_id' => $device->id,
            'error' => $error,
            'message' => $ticket['message'] ?? null,
        ]);

        // The explicit "this token is dead" signal; mirrors the web sender's
        // prune on an expired subscription.
        if ($error === self::DEVICE_NOT_REGISTERED) {
            $device->delete();
        }
    }

    private function client(): PendingRequest
    {
        $client = Http::acceptJson()->timeout((int) config('eventpulse.push.expo.timeout_seconds', 10));

        $accessToken = config('eventpulse.push.expo.access_token');

        return is_string($accessToken) && $accessToken !== ''
            ? $client->withToken($accessToken)
            : $client;
    }

    public function isEnabled(): bool
    {
        return (bool) config('eventpulse.push.expo.enabled');
    }
}
