<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivitySurface;
use App\Enums\ActivityType;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Jobs\ProcessActivitySignalJob;
use App\Models\Event;
use App\Services\Activity\ActivityLogger;
use App\Services\Activity\ClickDestinationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Record an outbound click from the app and return where to open.
 *
 * The web redirector (`go/{event}`) nudges the profile only for a session
 * user, and a native `Linking.openURL()` carries neither a cookie nor a
 * bearer token — so a mobile click through it would be logged but would
 * never train the recommender. This authenticated twin logs, dispatches
 * the signal, and hands the URL back for the client to open itself.
 */
class EventClickController extends Controller
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly ClickDestinationResolver $destinations,
    ) {}

    public function __invoke(Request $request, Event $event): JsonResponse
    {
        $event = $event->resolveCanonical();

        abort_if($event->is_hidden, 404);

        $destination = $this->destinations->resolve($event, $request->input('source'));

        abort_if($destination === null, 404);

        $user = $request->user();

        $log = $this->activity->log(
            ActivityType::EventClick,
            ActivitySurface::forApi($request, ActivitySurface::MobileEventDetail),
            eventId: $event->id,
            user: $user,
            context: ['authenticated' => true, 'source' => $destination['source']],
        );

        if ($log !== null && ! $log->is_bot) {
            ProcessActivitySignalJob::dispatch($log->id, $user->id);
        }

        return ApiResponse::item(['url' => $destination['url'], 'source' => $destination['source']]);
    }
}
