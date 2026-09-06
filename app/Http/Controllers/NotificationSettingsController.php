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
            'vapidPublicKey' => config('eventpulse.push.vapid.public_key'),
        ]);
    }

    public function update(NotificationSettingsRequest $request): RedirectResponse
    {
        /** @var array{channel: string, frequency: string, discovery_openness?: float} $validated */
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
        ]);
    }

    /**
     * API twin of update(): the same request, the user back instead of a redirect.
     */
    public function apiUpdate(NotificationSettingsRequest $request): JsonResponse
    {
        /** @var array{channel: string, frequency: string, discovery_openness?: float} $validated */
        $validated = $request->validated();

        $user = $request->user();
        $user->update($this->attributesFrom($validated));

        return ApiResponse::item(new UserResource($user->fresh()));
    }

    /**
     * Shared by both update paths so a new setting cannot land on one only.
     *
     * @param  array{channel: string, frequency: string, discovery_openness?: float}  $validated
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

        return $attributes;
    }
}
