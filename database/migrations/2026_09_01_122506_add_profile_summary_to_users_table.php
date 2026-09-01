<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The chat's own recap of what it learned, kept out of `interest_profile`
     * on purpose: that column is a flat map of numeric scores every scorer
     * iterates, and a free-text value in it would have to be special-cased in
     * ProfileScorer, ProfileUpdater and ProfileDecayer alike.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('profile_summary')->nullable()->after('interest_profile');
            $table->timestamp('profile_summary_updated_at')->nullable()->after('profile_summary');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['profile_summary', 'profile_summary_updated_at']);
        });
    }
};
