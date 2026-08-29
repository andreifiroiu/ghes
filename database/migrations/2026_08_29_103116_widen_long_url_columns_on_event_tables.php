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
     * `table => [column => [nullable, original varchar length]]`.
     *
     * Postgres refuses an over-long value rather than truncating it, so every
     * one of these columns can abort a whole write with a 22001. Observed:
     * allevents serves images through a CDN that embeds a base64 payload in
     * the URL path (266 chars), and reports a venue as its full postal address.
     *
     * @var array<string, array<string, array{bool, int}>>
     */
    private const COLUMNS = [
        'events' => [
            'title' => [false, 255],
            'source_url' => [false, 255],
            'source_id' => [true, 255],
            'venue' => [true, 255],
            'address' => [true, 255],
            'neighborhood' => [true, 100],
            'image_url' => [true, 255],
        ],
        'event_sources' => [
            'source_url' => [false, 255],
            'url_key' => [false, 255],
            'source_id' => [true, 255],
            'title' => [true, 255],
        ],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                foreach ($columns as $column => [$nullable]) {
                    $blueprint->text($column)->nullable($nullable)->change();
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
