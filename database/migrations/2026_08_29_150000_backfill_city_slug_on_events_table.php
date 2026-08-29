<?php

declare(strict_types=1);

use App\Services\Processing\EventTextNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill `events.city_slug` for every row that still has none.
 *
 * `2026_08_28_100001_add_canonical_fields_to_events_table` added the column
 * without populating it, and until now only `eventpulse:dedupe-events` (05:00
 * daily) filled it in. That was harmless while nothing read the column, but
 * recommendations, discovery and the dashboard counts now filter on it — so
 * between deploying that change and the next nightly run, every row would have
 * a NULL slug and every user's dashboard would render empty while truthfully
 * claiming there are no events in their city.
 *
 * Done in PHP rather than SQL because the slug folds diacritics
 * ("Timișoara" -> "timisoara"), which has no portable SQL equivalent here.
 */
return new class extends Migration
{
    public function up(): void
    {
        // chunkById, not chunk: the filter is `city_slug IS NULL` and the loop
        // body fills that column in, so every processed row leaves the result
        // set. Offset pagination would then skip a page's worth of unprocessed
        // rows on each iteration and silently leave a third of the table NULL.
        DB::table('events')
            ->select('id', 'city')
            ->whereNull('city_slug')
            ->whereNotNull('city')
            ->chunkById(500, function ($events): void {
                foreach ($events as $event) {
                    $slug = EventTextNormalizer::citySlug($event->city);

                    if ($slug === null) {
                        continue;
                    }

                    DB::table('events')->where('id', $event->id)->update(['city_slug' => $slug]);
                }
            });
    }

    public function down(): void
    {
        // Derived data — recomputable at any time, and dropping it would only
        // reintroduce the blank dashboard. Deliberately not reversed.
    }
};
