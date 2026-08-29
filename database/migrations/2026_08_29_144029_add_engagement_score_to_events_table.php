<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Behavioural popularity, 0–100, denormalised from `user_activity_logs`.
     *
     * Kept as a column rather than aggregated at read time so that scoring an
     * event stays a column read: RecommendationEngine scores a whole candidate
     * set per request, and a per-event aggregate there would be an N+1 over the
     * largest table in the schema. It also outlives the activity-log retention
     * window, so pruning raw rows does not erase what we learned from them.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->integer('engagement_score')->default(0)->after('popularity_score');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('engagement_score');
        });
    }
};
