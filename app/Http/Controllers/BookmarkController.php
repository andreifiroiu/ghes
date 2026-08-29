<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\BookmarkRequest;
use App\Http\Resources\EventResource;
use App\Services\Bookmarks\BookmarkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BookmarkController extends Controller
{
    public function __construct(
        private readonly BookmarkService $bookmarks,
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
        return EventResource::collection(
            $this->bookmarks->savedEventsFor($request->user()),
        )->response();
    }
}
