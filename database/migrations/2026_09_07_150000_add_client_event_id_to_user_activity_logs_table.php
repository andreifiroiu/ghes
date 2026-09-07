<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `CREATE INDEX CONCURRENTLY` cannot run inside a transaction. The
     * activity table is the largest in the schema (180 days of every page
     * view), and a plain unique constraint would build its index under an
     * ACCESS EXCLUSIVE lock, blocking every read and write for the duration.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('user_activity_logs', function (Blueprint $table) {
            // The app's own id for an impression it reports after the fact
            // (POST /activity). Null for everything the server logged itself.
            // Unique per user, so a batch replayed out of the client's buffer
            // cannot store the same impression twice; NULLs never collide.
            $table->uuid('client_event_id')->nullable()->after('notification_id');
        });

        // Same name Laravel would give the constraint, so `down()` is one
        // statement on either driver. Not a partial index: `insertOrIgnore`
        // relies on ON CONFLICT DO NOTHING picking the index up with no
        // conflict target named, and an unconditional index is the shape
        // that is certain to be usable there.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX CONCURRENTLY user_activity_logs_user_id_client_event_id_unique ON user_activity_logs (user_id, client_event_id)');

            return;
        }

        Schema::table('user_activity_logs', function (Blueprint $table) {
            $table->unique(['user_id', 'client_event_id']);
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS user_activity_logs_user_id_client_event_id_unique');
        } else {
            Schema::table('user_activity_logs', function (Blueprint $table) {
                $table->dropUnique(['user_id', 'client_event_id']);
            });
        }

        Schema::table('user_activity_logs', function (Blueprint $table) {
            $table->dropColumn('client_event_id');
        });
    }
};
