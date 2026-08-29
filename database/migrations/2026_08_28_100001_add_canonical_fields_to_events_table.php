<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Blocking key: "{city-slug}|{local-date}|{title-token-key}".
            // Indexed but deliberately NOT unique — it is a lossy key, and the
            // fuzzy path must still be able to attach a source to an event
            // whose key differs slightly.
            $table->string('match_key', 191)->nullable()->after('fingerprint');
            $table->string('city_slug')->nullable()->after('city');
            $table->date('local_date')->nullable()->after('starts_at');
            $table->foreignUuid('merged_into_id')->nullable()->after('id')
                ->constrained('events')->nullOnDelete();
            $table->unsignedInteger('sources_count')->default(1)->after('popularity_score');
            $table->timestamp('last_seen_at')->nullable()->after('sources_count');

            $table->index('match_key');
            $table->index(['city_slug', 'local_date']);
        });

        // Identity now lives in event_sources. These two uniques would block
        // both the merge model and any refresh of an already-imported event.
        Schema::table('events', function (Blueprint $table) {
            $table->dropUnique(['source_url']);
            $table->dropUnique(['fingerprint']);
        });

        Schema::table('events', function (Blueprint $table) {
            $table->index('source_url');
        });

        // The fingerprint is superseded by match_key + event_sources. Keep the
        // column for now so nothing breaks mid-rollout, but stop requiring it.
        Schema::table('events', function (Blueprint $table) {
            $table->string('fingerprint')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('fingerprint')->nullable(false)->change();
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['source_url']);
        });

        Schema::table('events', function (Blueprint $table) {
            $table->unique('source_url');
            $table->unique('fingerprint');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['city_slug', 'local_date']);
            $table->dropIndex(['match_key']);
            $table->dropConstrainedForeignId('merged_into_id');
            $table->dropColumn([
                'match_key',
                'city_slug',
                'local_date',
                'sources_count',
                'last_seen_at',
            ]);
        });
    }
};
