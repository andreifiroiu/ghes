<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\ActivityType;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\ScraperRun;
use App\Models\User;
use App\Models\UserActivityLog;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $lastWeek = now()->subDays(7);

        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'events' => [
                    'total' => Event::count(),
                    'upcoming' => Event::upcoming()->count(),
                    'classified' => Event::where('is_classified', true)->count(),
                    'geocoded' => Event::where('is_geocoded', true)->count(),
                    'hidden' => Event::where('is_hidden', true)->count(),
                ],
                'users' => [
                    'total' => User::count(),
                    'onboarded' => User::where('onboarding_completed', true)->count(),
                ],
                'scraper_runs' => [
                    'total' => ScraperRun::count(),
                    'failed' => ScraperRun::where('status', 'failed')->count(),
                ],
                // Headline engagement only — the full report lives under
                // /admin/analytics, which this is meant to point at.
                'activity' => [
                    'clicks_7d' => UserActivityLog::query()
                        ->human()
                        ->ofType(ActivityType::EventClick)
                        ->where('created_at', '>=', $lastWeek)
                        ->count(),
                    'views_7d' => UserActivityLog::query()
                        ->human()
                        ->ofType(ActivityType::EventView)
                        ->where('created_at', '>=', $lastWeek)
                        ->count(),
                ],
            ],
        ]);
    }
}
