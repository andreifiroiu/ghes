<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Concerns;

use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Throwable;

/**
 * Shared filtering helpers for the admin event screens: the calendar
 * interval both of them accept, and the option lists their selects offer.
 */
trait FiltersAdminEvents
{
    /**
     * Narrow a query to events starting inside a calendar interval.
     *
     * The bounds are calendar days in the city's timezone, not UTC: an admin
     * asking for "today" means the local day, and an event at 01:00 local on
     * the 5th is stored as 22:00 UTC on the 4th.
     *
     * @param  Builder<Event>  $query
     */
    protected function applyDateRange(Builder $query, Request $request): void
    {
        $timezone = $this->cityTimezone();

        if ($request->filled('date_from')) {
            $from = $this->parseDate($request->string('date_from')->toString(), $timezone);

            if ($from !== null) {
                $query->where('starts_at', '>=', $from->startOfDay()->utc());
            }
        }

        if ($request->filled('date_to')) {
            $to = $this->parseDate($request->string('date_to')->toString(), $timezone);

            if ($to !== null) {
                $query->where('starts_at', '<=', $to->endOfDay()->utc());
            }
        }
    }

    /**
     * The timezone admin-entered calendar dates are read in.
     */
    protected function cityTimezone(): string
    {
        $city = (string) config('eventpulse.default_city');

        return (string) config("eventpulse.cities.{$city}.timezone", config('app.timezone'));
    }

    /**
     * Every provider that has actually produced an event.
     *
     * @return list<string>
     */
    protected function knownSources(): array
    {
        /** @var list<string> $sources */
        $sources = Event::query()
            ->select('source')
            ->distinct()
            ->orderBy('source')
            ->pluck('source')
            ->all();

        return $sources;
    }

    /**
     * Every city events have actually been scraped for.
     *
     * @return list<string>
     */
    protected function knownCities(): array
    {
        /** @var list<string> $cities */
        $cities = Event::query()
            ->whereNotNull('city')
            ->select('city')
            ->distinct()
            ->orderBy('city')
            ->pluck('city')
            ->all();

        return $cities;
    }

    private function parseDate(string $date, string $timezone): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($date, $timezone);
        } catch (Throwable) {
            // An unparseable bound is ignored rather than emptying the list.
            return null;
        }
    }
}
