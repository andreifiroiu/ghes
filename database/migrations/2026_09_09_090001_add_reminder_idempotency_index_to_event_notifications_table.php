<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The at-most-once guarantee for reminders.
     *
     * Overlapping scheduler runs, a retried job and two workers racing all end
     * at the same insert; this index is what makes the loser a no-op rather
     * than a second email. The composer's whereNotExists is only a cheap
     * pre-filter — it cannot be relied on across concurrent runs.
     *
     * Digests are unaffected because both discriminator columns are null on
     * every digest row, and nulls are distinct in a unique index on
     * PostgreSQL, MySQL and SQLite alike. That only holds while digests leave
     * event_id null: never backfill it onto them.
     *
     * Kept in its own migration so it can be dropped without losing the
     * columns if it ever needs reshaping.
     */
    public function up(): void
    {
        Schema::table('event_notifications', function (Blueprint $table) {
            $table->unique(['user_id', 'event_id', 'lead_minutes'], 'event_notifications_reminder_unique');
        });
    }

    public function down(): void
    {
        Schema::table('event_notifications', function (Blueprint $table) {
            $table->dropUnique('event_notifications_reminder_unique');
        });
    }
};
