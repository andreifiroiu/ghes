<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\NotificationChannel;
use App\Enums\NotificationFrequency;
use App\Http\Requests\NotificationSettingsRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationSettingsController extends Controller
{
    public function show(Request $request): Response
    {
        return Inertia::render('Settings/Notifications', [
            'user' => new UserResource($request->user()),
            'channels' => array_column(NotificationChannel::cases(), 'value'),
            'frequencies' => array_column(NotificationFrequency::cases(), 'value'),
            'reminderLeadOptions' => self::reminderLeadOptions(),
            'vapidPublicKey' => config('eventpulse.push.vapid.public_key'),
        ]);
    }

    public function update(NotificationSettingsRequest $request): RedirectResponse
    {
        /** @var array{channel: string, frequency: string, discovery_openness?: float, event_reminders?: bool, reminder_lead_minutes?: list<int>} $validated */
        $validated = $request->validated();

        $request->user()->update($this->attributesFrom($validated));

        return redirect()->back()
            ->with('success', 'Notification settings updated.');
    }

    /**
     * API twin of show(). No VAPID key: native push does not use it.
     */
    public function apiShow(Request $request): JsonResponse
    {
        return ApiResponse::item([
            'user' => (new UserResource($request->user()))->resolve(),
            'channels' => array_column(NotificationChannel::cases(), 'value'),
            'frequencies' => array_column(NotificationFrequency::cases(), 'value'),
            'reminder_lead_options' => self::reminderLeadOptions(),
        ]);
    }

    /**
     * API twin of update(): the same request, the user back instead of a redirect.
     */
    public function apiUpdate(NotificationSettingsRequest $request): JsonResponse
    {
        /** @var array{channel: string, frequency: string, discovery_openness?: float, event_reminders?: bool, reminder_lead_minutes?: list<int>} $validated */
        $validated = $request->validated();

        $user = $request->user();
        $user->update($this->attributesFrom($validated));

        return ApiResponse::item(new UserResource($user->fresh()));
    }

    /**
     * Shared by both update paths so a new setting cannot land on one only.
     *
     * @param  array{channel: string, frequency: string, discovery_openness?: float, event_reminders?: bool, reminder_lead_minutes?: list<int>}  $validated
     * @return array<string, mixed>
     */
    private function attributesFrom(array $validated): array
    {
        $attributes = [
            'notification_channel' => NotificationChannel::from($validated['channel']),
            'notification_frequency' => NotificationFrequency::from($validated['frequency']),
        ];

        // discovery_openness is only sent by the profile page (which has the slider);
        // the dedicated settings page omits it, so update it only when present.
        if (array_key_exists('discovery_openness', $validated)) {
            $attributes['discovery_openness'] = (float) $validated['discovery_openness'];
        }

        if (array_key_exists('event_reminders', $validated)) {
            $attributes['event_reminders_enabled'] = (bool) $validated['event_reminders'];
        }

        if (array_key_exists('reminder_lead_minutes', $validated)) {
            $attributes['reminder_lead_minutes'] = array_values(array_unique(
                array_map(intval(...), $validated['reminder_lead_minutes']),
            ));
        }

        return $attributes;
    }

    /**
     * The lead times a user may pick from, so both clients render the same
     * choices rather than hardcoding minutes that config could change.
     *
     * @return list<int>
     */
    private static function reminderLeadOptions(): array
    {
        return array_map(intval(...), (array) config('eventpulse.reminders.lead_options', []));
    }
}
