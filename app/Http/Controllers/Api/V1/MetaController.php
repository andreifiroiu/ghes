<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\EventCategory;
use App\Enums\NotificationChannel;
use App\Enums\NotificationFrequency;
use App\Enums\Reaction;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\City\CityCatalog;
use Illuminate\Http\JsonResponse;

/**
 * Everything a client needs to know before it renders its first screen.
 *
 * The vocabularies here are the backing values of the enums the API accepts
 * and returns, so a client that populates its chips from this response cannot
 * send `Tech` where the server expects `technology` — that exact bug has
 * already shipped once on the web. Public, because the app needs it before
 * sign-in and nothing in it is per-user.
 */
class MetaController extends Controller
{
    public function __invoke(): JsonResponse
    {
        /** @var array<string, array{label?: string, timezone?: string, coordinates?: array{0: float, 1: float}}> $configured */
        $configured = (array) config('eventpulse.cities', []);

        $cities = [];

        foreach ($configured as $key => $city) {
            $cities[] = [
                'key' => (string) $key,
                // The label is what `users.city` stores and what the profile
                // endpoints validate against — see CityCatalog.
                'label' => (string) ($city['label'] ?? $key),
                'timezone' => (string) ($city['timezone'] ?? config('app.timezone')),
            ];
        }

        return ApiResponse::item([
            'categories' => array_column(EventCategory::cases(), 'value'),
            'cities' => $cities,
            'default_city' => CityCatalog::defaultLabel(),
            'reactions' => array_column(Reaction::cases(), 'value'),
            'ranges' => ['weekend'],
            'notification_channels' => array_column(NotificationChannel::cases(), 'value'),
            'notification_frequencies' => array_column(NotificationFrequency::cases(), 'value'),
            // Minutes before an event starts. The client renders one checkbox
            // per entry rather than hardcoding a list that config can change.
            'reminder_lead_options' => array_map(intval(...), (array) config('eventpulse.reminders.lead_options', [])),
            'page_size' => (int) config('eventpulse.pagination.events'),
            'min_supported_app_version' => config('eventpulse.mobile.min_supported_version'),
        ]);
    }
}
