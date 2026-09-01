<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Services\City\CityCatalog;
use Carbon\Carbon;

/**
 * City label, timezone and weekend-range resolution against the configured
 * default city. Previously copied privately into several controllers.
 */
trait ResolvesCity
{
    /**
     * The configured default city's display label (e.g. "Timișoara").
     */
    protected function cityLabel(): string
    {
        return CityCatalog::defaultLabel();
    }

    /**
     * Resolve the timezone used to interpret a user-selected calendar date,
     * defaulting to the configured default city's timezone.
     */
    protected function cityTimezone(): string
    {
        $city = (string) config('eventpulse.default_city');

        return (string) config("eventpulse.cities.{$city}.timezone", config('app.timezone'));
    }

    /**
     * The upcoming weekend in the city's timezone, as a UTC range. During a
     * weekend it means the one in progress, not the next one.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function weekendRange(): array
    {
        $now = Carbon::now($this->cityTimezone());

        $start = $now->isSaturday() || $now->isSunday()
            ? $now->copy()->startOfDay()
            : $now->copy()->next(Carbon::SATURDAY)->startOfDay();

        $end = $now->isSunday()
            ? $start->copy()->endOfDay()
            : $start->copy()->addDay()->endOfDay();

        return [$start->utc(), $end->utc()];
    }
}
