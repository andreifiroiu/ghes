<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `events.sources_count` used to count event_sources rows, so an event one
     * provider listed under two URLs read as "2 surse". It now counts distinct
     * providers (EventMerger::providerCount); bring existing rows in line.
     * Events with no provenance rows keep their count of 1.
     */
    public function up(): void
    {
        DB::table('event_sources')
            ->select('event_id')
            ->selectRaw('count(distinct source) as providers')
            ->groupBy('event_id')
            ->orderBy('event_id')
            ->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('events')
                        ->where('id', $row->event_id)
                        ->where('sources_count', '!=', (int) $row->providers)
                        ->update(['sources_count' => max(1, (int) $row->providers)]);
                }
            });
    }

    /**
     * The old per-row count is not worth restoring; nothing reads it.
     */
    public function down(): void {}
};
