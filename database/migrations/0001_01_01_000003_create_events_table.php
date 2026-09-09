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
        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('source');
            $table->string('source_url')->unique();
            $table->string('source_id')->nullable();
            $table->string('fingerprint')->unique();
            $table->string('category');
            $table->jsonb('tags')->default(DB::raw("('[]')"));
            $table->string('venue')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->decimal('price_min', 10, 2)->nullable();
            $table->decimal('price_max', 10, 2)->nullable();
            $table->string('currency', 3)->default('RON');
            $table->boolean('is_free')->default(false);
            $table->string('image_url')->nullable();
            $table->jsonb('metadata')->default(DB::raw("('{}')"));
            $table->integer('popularity_score')->default(0);
            $table->boolean('is_classified')->default(false);
            $table->boolean('is_geocoded')->default(false);
            $table->boolean('is_enriched')->default(false);
            $table->timestamps();

            $table->index('category');
            $table->index('city');
            $table->index('starts_at');
            $table->index('source');
            $table->index(['is_classified', 'is_geocoded', 'is_enriched']);
        });

        // GIN indexes on JSONB columns for fast queries (PostgreSQL only)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX events_tags_gin ON events USING GIN (tags)');
            DB::statement('CREATE INDEX events_metadata_gin ON events USING GIN (metadata)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
