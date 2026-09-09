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
        Schema::create('scraper_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source');
            $table->string('status'); // 'running', 'completed', 'failed'
            $table->integer('events_found')->default(0);
            $table->integer('events_created')->default(0);
            $table->integer('events_updated')->default(0);
            $table->integer('events_skipped')->default(0);
            $table->integer('errors_count')->default(0);
            $table->json('error_log')->default(DB::raw("('[]')"));
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['source', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scraper_runs');
    }
};
