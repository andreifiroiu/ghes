<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_activity_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Nullable: events browsing is public and the signed email routes
            // carry no session, so guest and mail-client traffic must still
            // record. A null user_id counts toward an event's popularity but
            // can never feed an interest profile.
            $table->foreignUuid('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('notification_id')->nullable()
                ->constrained('event_notifications')->nullOnDelete();

            $table->string('type');
            $table->string('surface');

            // Salted hash of the session id. Enough to tell one guest's page
            // views apart from another's; not enough to identify anyone. No IP
            // and no raw user agent are stored.
            $table->string('session_key', 64)->nullable();

            // Mail scanners and link prefetchers fetch every URL in a digest.
            // Those hits are real traffic and stay in the table, but they are
            // flagged so CTR and the ranking aggregate can exclude them.
            $table->boolean('is_bot')->default(false);

            $table->jsonb('context')->default('{}');
            $table->timestamps();

            $table->index(['user_id', 'type', 'created_at']);
            $table->index(['event_id', 'type']);
            $table->index(['type', 'is_bot', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX user_activity_logs_context_gin ON user_activity_logs USING GIN (context)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_activity_logs');
    }
};
