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
        Schema::create('event_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel');
            $table->string('frequency');
            $table->json('event_ids')->default(DB::raw("('[]')"));
            $table->json('discovery_event_ids')->default(DB::raw("('[]')"));
            $table->text('subject')->nullable();
            $table->text('body_html')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'sent_at']);
            $table->index('channel');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_notifications');
    }
};
