<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\ScraperRun;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
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
            ],
        ]);
    }
}
