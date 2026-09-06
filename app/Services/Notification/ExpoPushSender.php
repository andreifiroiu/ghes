<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Contracts\PushChannel;
use App\Jobs\FetchExpoPushReceiptsJob;
use App\Models\Device;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Delivers through the Expo Push Service.
 *
 * One HTTPS endpoint and one credential instead of an APNs key plus an FCM
 * service account. Tickets come back synchronously per message; receipts
 * (which is where `DeviceNotRegistered` usually shows up) arrive later and
 * are fetched by a delayed job. Runs inside SendNativePushJob, so a failure
 * to reach the service throws and is retried there.
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

        foreach ($devices->chunk((int) config('eventpulse.push.expo.batch_size', 100)) as $chunk) {
            $accepted += $this->sendBatch($chunk->values(), $payload);
        }

        return $accepted;
    }

    /**
     * @param  Collection<int, Device>  $devices
     */
    private function sendBatch(Collection $devices, PushPayload $payload): int
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

        try {
            $response = $this->client()->post((string) config('eventpulse.push.expo.endpoint'), $messages);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Expo push service unreachable: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            // Retried by the job. A push-only user has no email to fall
            // back on, so dropping this would lose the digest outright.
            throw new RuntimeException(sprintf(
                'Expo push service rejected the batch (%d): %s',
                $response->status(),
                mb_substr($response->body(), 0, 500),
            ));
        }

        $tickets = $response->json('data');

        if (! is_array($tickets) || count($tickets) !== $devices->count()) {
            // A request-level error (e.g. too many experience ids) comes back
            // as `errors` with no `data`; a short list means some messages
            // were dropped. Either way, say so instead of under-counting.
            Log::error('Expo returned fewer tickets than messages', [
                'expected' => $devices->count(),
                'got' => is_array($tickets) ? count($tickets) : 0,
                'errors' => $response->json('errors'),
            ]);

            $tickets = is_array($tickets) ? $tickets : [];
        }

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

    /**
     * The HTTP client both the send and the receipts request use, so the
     * access token (required once Expo's enhanced push security is on)
     * cannot be attached to one and forgotten on the other.
     */
    public function client(): PendingRequest
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
