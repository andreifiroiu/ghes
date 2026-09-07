<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A token pair belongs to one installed app; `device_id` is what ties the
     * access and refresh tokens together and what logout revokes by.
     */
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->uuid('device_id')->nullable()->index()->after('expires_at');
            $table->string('device_name', 100)->nullable()->after('device_id');
            $table->string('platform', 16)->nullable()->after('device_name');
            $table->string('app_version', 32)->nullable()->after('platform');
            // When this device first signed in. Rotation replaces the rows,
            // so the value is carried forward rather than read off created_at.
            $table->timestamp('signed_in_at')->nullable()->after('app_version');
        });

        // Tokens issued before device binding carried every ability and no
        // expiry. Nothing in the wild holds one (the API had no consumers),
        // and keeping them would leave rows that pass every ability check but
        // can never be refreshed or listed. Revoke rather than migrate.
        DB::table('personal_access_tokens')->whereNull('device_id')->delete();
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropColumn(['device_id', 'device_name', 'platform', 'app_version', 'signed_in_at']);
        });
    }
};
