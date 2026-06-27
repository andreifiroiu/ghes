<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('event_notifications', function (Blueprint $table) {
            // Marks when "ignored" passive decay was applied for this batch,
            // so its un-reacted events are only decayed once.
            $table->timestamp('decay_applied_at')->nullable()->after('opened_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_notifications', function (Blueprint $table) {
            $table->dropColumn('decay_applied_at');
        });
    }
};
