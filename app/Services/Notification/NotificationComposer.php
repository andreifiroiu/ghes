<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Enums\NotificationChannel;
use App\Enums\NotificationFrequency;
use App\Models\Notification;
use App\Models\User;
use App\Services\Recommendation\RecommendationEngine;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class NotificationComposer
{
    public function __construct(
        private readonly RecommendationEngine $recommendationEngine,
    ) {}

    /**
     * Compose a notification for a single user.
     *
     * Returns null when there are no events to recommend.
     */
    public function compose(User $user): ?Notification
    {
        $maxEvents = (int) config('eventpulse.notifications.max_events_per_digest', 10);

        $batch = $this->recommendationEngine->recommend($user, $maxEvents);

        if ($batch->recommendedEventIds === [] && $batch->discoveryEventIds === []) {
            return null;
        }

        $date = Carbon::now($user->timezone ?? 'UTC')->format('l, M j');

        $notification = new Notification([
            'user_id' => $user->id,
            'channel' => $user->notification_channel ?? NotificationChannel::Email,
            'frequency' => $user->notification_frequency ?? NotificationFrequency::Daily,
            'event_ids' => $batch->recommendedEventIds,
            'discovery_event_ids' => $batch->discoveryEventIds,
            'subject' => "Evenimentele tale Ghes pentru {$date}",
        ]);

        $notification->save();

        return $notification;
    }

    /**
     * Compose notifications for all users who are due.
     *
     * @return Collection<int, Notification>
     */
    public function composeForAll(): Collection
    {
        $users = $this->dueUsers();
        $composed = collect();

        foreach ($users as $user) {
            try {
                $notification = $this->compose($user);

                if ($notification !== null) {
                    $composed->push($notification);
                }
            } catch (\Throwable $e) {
                Log::error('Failed to compose notification', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info("Composed {$composed->count()} notifications for {$users->count()} eligible users");

        return $composed;
    }

    /**
     * Query users who are due for a notification.
     *
     * @return Collection<int, User>
     */
    private function dueUsers(): Collection
    {
        return User::where('onboarding_completed', true)
            ->get()
            ->filter(function (User $user) {
                $lastSent = Notification::where('user_id', $user->id)
                    ->whereNotNull('sent_at')
                    ->latest('sent_at')
                    ->value('sent_at');

                if ($lastSent === null) {
                    return true; // Never notified
                }

                $lastSent = Carbon::parse($lastSent);

                return match ($user->notification_frequency) {
                    NotificationFrequency::Daily => $lastSent->lt(now()->subDay()),
                    NotificationFrequency::Weekly => $lastSent->lt(now()->subWeek()),
                    NotificationFrequency::Realtime => true,
                    default => $lastSent->lt(now()->subDay()),
                };
            })
            ->values();
    }
}
