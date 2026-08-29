<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\ScraperRun;
use App\Services\Activity\ActivityReporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminStatsController extends Controller
{
    public function __construct(
        private readonly ActivityReporter $reporter,
    ) {}

    /**
     * Ingestion stats for the admin dashboard.
     */
    public function eventStats(): JsonResponse
    {
        return response()->json([
            'events' => [
                'total' => Event::count(),
                'classified' => Event::where('is_classified', true)->count(),
                'geocoded' => Event::where('is_geocoded', true)->count(),
                'enriched' => Event::where('is_enriched', true)->count(),
                'upcoming' => Event::upcoming()->count(),
                'by_category' => Event::query()
                    ->get(['category'])
                    ->countBy(fn (Event $event) => (string) $event->getRawOriginal('category')),
            ],
            'scraper_runs' => [
                'total' => ScraperRun::count(),
                'failed' => ScraperRun::where('status', 'failed')->count(),
            ],
        ]);
    }

    /**
     * Engagement stats for the admin dashboard: what users did, as opposed to
     * what the pipeline ingested.
     */
    public function activityStats(Request $request): JsonResponse
    {
        $window = (int) $request->integer('window', 30);

        return response()->json(
            $this->reporter->summary(in_array($window, [7, 30, 90], true) ? $window : 30),
        );
    }
}
