<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();

            $table->string('source');
            $table->string('source_url');
            // Canonicalised source_url: lowercase host, no www./m., no query, no trailing slash.
            $table->string('url_key');
            $table->string('source_id')->nullable();
            // Local 'Y-m-d' or 'undated'. Part of the unique key so a genuinely
            // recurring event that reuses one URL gets one row per occurrence.
            // NOT NULL so NULL-distinctness can never weaken the constraint.
            $table->string('occurrence_key', 10);

            // What this particular provider reported, kept alongside the merged
            // canonical values on the events row.
            $table->string('title')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->jsonb('payload')->default('{}');

            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['source', 'url_key', 'occurrence_key']);
            $table->unique(['source', 'source_id', 'occurrence_key']);
            $table->index(['event_id', 'source']);
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_sources');
    }
};
