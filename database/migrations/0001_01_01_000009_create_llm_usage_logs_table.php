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
        Schema::create('llm_usage_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('operation'); // 'classification', 'onboarding_chat', 'profile_generation', etc.
            $table->string('model');
            $table->integer('input_tokens');
            $table->integer('output_tokens');
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->json('metadata')->default(DB::raw("('{}')"));
            $table->timestamps();

            $table->index(['operation', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_usage_logs');
    }
};
