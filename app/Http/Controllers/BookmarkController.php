<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivitySurface;
use App\Enums\ActivityType;
use App\Http\Middleware\ResolveClientSurface;
use App\Http\Requests\BookmarkRequest;
use App\Http\Resources\EventResource;
use App\Http\Responses\ApiResponse;
use App\Services\Activity\ActivityLogger;
use App\Services\Bookmarks\BookmarkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BookmarkController extends Controller
{
    public function __construct(
        private readonly BookmarkService $bookmarks,
        private readonly ActivityLogger $activity,
    ) {}

    public function store(BookmarkRequest $request): JsonResponse
    {
        /** @var array{event_id: string} $validated */
        $validated = $request->validated();

        $this->bookmarks->add($request->user(), $validated['event_id']);

        return response()->json(['message' => 'Event saved.']);
    }

    public function destroy(BookmarkRequest $request): JsonResponse
    {
        /** @var array{event_id: string} $validated */
        $validated = $request->validated();

        $this->bookmarks->remove($request->user(), $validated['event_id']);

        return response()->json(['message' => 'Event unsaved.']);
    }

    public function index(Request $request): Response
    {
        return Inertia::render('Dashboard/SavedEvents', [
            'events' => EventResource::collection(
                $this->bookmarks->savedEventsFor($request->user()),
            )->resolve(),
        ]);
    }

    public function apiIndex(Request $request): JsonResponse
    {
        $events = $this->bookmarks->paginateSavedEventsFor(
            $request->user(),
            (int) config('eventpulse.pagination.events', 20),
        );

        // The saved screen is a surface of its own; without this the app's
        // funnel would have no `mobile_saved` rows to read.
        $this->activity->logMany(
            ActivityType::EventImpression,
            ResolveClientSurface::surfaceFor($request, ActivitySurface::MobileSaved),
            array_column($events->items(), 'id'),
            $request->user(),
        );

        return ApiResponse::paginated(EventResource::collection($events));
    }

    /**
     * API twin of store(). Idempotent — a retried save is a no-op — so the
     * client may resend it blindly on a network blip.
     */
    public function apiStore(BookmarkRequest $request): JsonResponse
    {
        /** @var array{event_id: string} $validated */
        $validated = $request->validated();

        $this->bookmarks->add($request->user(), $validated['event_id']);

        return ApiResponse::message('Event saved.');
    }

    /**
     * API twin of destroy().
     */
    public function apiDestroy(BookmarkRequest $request): JsonResponse
    {
        /** @var array{event_id: string} $validated */
        $validated = $request->validated();

        $this->bookmarks->remove($request->user(), $validated['event_id']);

        return ApiResponse::message('Event unsaved.');
    }
}
