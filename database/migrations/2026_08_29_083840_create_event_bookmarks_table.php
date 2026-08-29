<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_bookmarks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();

            // The effective, post-clamp profile change this bookmark contributed,
            // keyed by profile key ("music", "tag:jazz"). Reversed verbatim when
            // the bookmark is removed. Null means "unknown provenance, nothing to
            // reverse" (legacy rows migrated from the old `saved` reaction).
            $table->json('applied_deltas')->nullable();
            $table->boolean('is_processed')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'event_id']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_bookmarks');
    }
};
