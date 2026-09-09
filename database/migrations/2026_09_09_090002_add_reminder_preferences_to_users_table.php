<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('event_reminders_enabled')->default(true)->after('notification_frequency');

            // Null means "whatever eventpulse.reminders.default_lead_minutes
            // says", so changing the product default later still reaches
            // everyone who never opened the setting. Deliberately nullable
            // rather than defaulted: a JSON column default has to be written
            // as a driver expression to stay runnable on MySQL, and there is
            // nothing to gain from carrying that here.
            $table->json('reminder_lead_minutes')->nullable()->after('event_reminders_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['event_reminders_enabled', 'reminder_lead_minutes']);
        });
    }
};
