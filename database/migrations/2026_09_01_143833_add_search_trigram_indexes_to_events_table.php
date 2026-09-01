<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Back the database search fallback with trigram indexes.
     *
     * `EventSearcher` falls back to a case-insensitive LIKE across title, venue
     * and description whenever Meilisearch cannot answer. Those patterns lead
     * with a wildcard, which no btree index can serve — without pg_trgm the
     * fallback sequentially scans `events` on every keystroke of a live search.
     *
     * PostgreSQL only, matching the JSONB GIN indexes in the events migration:
     * the sqlite test connection has no pg_trgm and does not need one.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // IF NOT EXISTS on each: three GIN builds on a populated table are not
        // instant, and if the last one is cancelled or hits a statement timeout
        // the first two survive while the migration is never recorded. Without
        // this, the retry dies on "relation already exists" and the deploy can
        // only proceed after someone drops the indexes by hand on production.
        DB::statement('CREATE INDEX IF NOT EXISTS events_title_trgm ON events USING GIN (title gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS events_venue_trgm ON events USING GIN (venue gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS events_description_trgm ON events USING GIN (description gin_trgm_ops)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS events_title_trgm');
        DB::statement('DROP INDEX IF EXISTS events_venue_trgm');
        DB::statement('DROP INDEX IF EXISTS events_description_trgm');

        // The extension is left in place: another table may have come to rely
        // on it, and dropping it would take their indexes with it.
    }
};
