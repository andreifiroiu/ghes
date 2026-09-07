<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\ClientActivityEvent;
use App\Enums\ActivitySurface;
use App\Enums\ActivityType;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveClientSurface;
use App\Http\Requests\Api\ActivityBatchRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Activity\ClientActivityRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * Receive the app's buffered impressions.
 *
 * Server-side impression logging counts a card as seen the moment a page is
 * served — every card on page two of an infinite list, scrolled to or not.
 * The app measures the real thing (60 % visible for a second) and reports it
 * here in batches, with its own ids so a retried flush is a no-op.
 */
class ActivityBatchController extends Controller
{
    public function __construct(
        private readonly ClientActivityRecorder $recorder,
    ) {}

    public function __invoke(ActivityBatchRequest $request): JsonResponse
    {
        /** @var list<array<string, mixed>> $items */
        $items = $request->validated('events');

        $events = array_map(fn (array $item): ClientActivityEvent => new ClientActivityEvent(
            id: $item['id'],
            type: ActivityType::from($item['type']),
            surface: ResolveClientSurface::surfaceFrom($request, $item['from'] ?? null, ActivitySurface::MobileBrowse),
            eventId: $item['event_id'],
            // Validated against an explicit ISO 8601 format with an offset,
            // so this cannot throw and a device's local time cannot arrive
            // offset-less and be read as UTC.
            occurredAt: CarbonImmutable::parse($item['at']),
        ), $items);

        $result = $this->recorder->record($request->user(), $events);

        return ApiResponse::message(
            'Activity recorded.',
            [
                'accepted' => $result->accepted,
                'duplicates' => $result->duplicates,
                'dropped' => $result->dropped,
            ],
            202,
        );
    }
}
