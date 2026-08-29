<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Activity\ActivityReporter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    /**
     * Windows the report can be run over, in days.
     *
     * @var list<int>
     */
    private const WINDOWS = [7, 30, 90];

    public function __construct(
        private readonly ActivityReporter $reporter,
    ) {}

    public function index(Request $request): Response
    {
        $window = (int) $request->integer('window', 30);

        if (! in_array($window, self::WINDOWS, true)) {
            $window = 30;
        }

        return Inertia::render('Admin/Analytics', [
            'summary' => $this->reporter->summary($window),
            'windows' => self::WINDOWS,
        ]);
    }
}
