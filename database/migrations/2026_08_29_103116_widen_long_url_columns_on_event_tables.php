<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Third-party text with no length guarantee, as
     * `table => [column => [nullable, original varchar length, MySQL width]]`.
     *
     * Postgres refuses an over-long value rather than truncating it, so every
     * one of these columns can abort a whole write with a 22001. Observed:
     * allevents serves images through a CDN that embeds a base64 payload in
     * the URL path (266 chars), and reports a venue as its full postal address.
     *
     * MySQL cannot index TEXT without a prefix length, so on MySQL an indexed
     * column becomes the widest varchar its index still fits instead: InnoDB
     * caps a key at 3072 bytes, utf8mb4 spends 4 per char, and the
     * event_sources uniques share that budget with source (255) and
     * occurrence_key (10). That leaves 12 bytes spare — widening source or
     * occurrence_key later breaks both uniques. Unindexed columns become TEXT
     * everywhere.
     *
     * These widths make the migrations run on MySQL; they do not make the app
     * equivalent there. On MySQL these columns inherit utf8mb4_unicode_ci,
     * which is case- and accent-insensitive, so url_key and source_id compare
     * "timisoara" equal to "timișoara" and EventDeduplicator would treat two
     * distinct events as one. PostgreSQL text is sensitive on both counts.
     * Deploy on PostgreSQL; a real MySQL target needs a binary collation on
     * the identity columns first.
     *
     * @var array<string, array<string, array{bool, int, int|null}>>
     */
    private const COLUMNS = [
        'events' => [
            'title' => [false, 255, null],
            'source_url' => [false, 255, 768],
            'source_id' => [true, 255, null],
            'venue' => [true, 255, null],
            'address' => [true, 255, null],
            'neighborhood' => [true, 100, null],
            'image_url' => [true, 255, null],
        ],
        'event_sources' => [
            'source_url' => [false, 255, null],
            'url_key' => [false, 255, 500],
            'source_id' => [true, 255, 500],
            'title' => [true, 255, null],
        ],
    ];

    public function up(): void
    {
        $isMySql = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);

        foreach (self::COLUMNS as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $isMySql) {
                foreach ($columns as $column => [$nullable, , $mySqlWidth]) {
                    $definition = $isMySql && $mySqlWidth !== null
                        ? $blueprint->string($column, $mySqlWidth)
                        : $blueprint->text($column);

                    $definition->nullable($nullable)->change();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column => [, $length]) {
                // The narrower column cannot hold what text has been accepting
                // since `up()`; trim first so the rollback cannot itself abort.
                // substr() rather than left() — left() does not exist on sqlite.
                DB::table($table)
                    ->whereRaw("length({$column}) > {$length}")
                    ->update([$column => DB::raw("substr({$column}, 1, {$length})")]);
            }

            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                foreach ($columns as $column => [$nullable, $length]) {
                    $blueprint->string($column, $length)->nullable($nullable)->change();
                }
            });
        }
    }
};
