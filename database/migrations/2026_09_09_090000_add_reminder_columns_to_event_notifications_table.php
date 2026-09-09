<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_notifications', function (Blueprint $table) {
            // Every existing row is a digest, so the default is the backfill.
            $table->string('type')->default('digest')->after('user_id');

            // The single event a reminder is about. Digests leave it null, and
            // that null is what keeps them out of the reminder unique index.
            //
            // nullOnDelete, not cascade: CleanupExpiredEventsJob hard-deletes
            // events long past, and the notification history should outlive the
            // event it was about.
            $table->foreignUuid('event_id')->nullable()->after('type')
                ->constrained()->nullOnDelete();

            // Which lead tier produced this row, and part of the idempotency
            // key — the day-before and the 3h reminder for one event are two
            // distinct rows. Permanent per (user, event, tier): if a scraper
            // later corrects starts_at, that tier will not fire again.
            $table->unsignedSmallInteger('lead_minutes')->nullable()->after('event_id');

            $table->index(['type', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::table('event_notifications', function (Blueprint $table) {
            $table->dropIndex(['type', 'sent_at']);
            $table->dropConstrainedForeignId('event_id');
            $table->dropColumn(['type', 'lead_minutes']);
        });
    }
};
