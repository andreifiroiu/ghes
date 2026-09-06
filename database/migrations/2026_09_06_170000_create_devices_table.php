<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 16);
            // Unique on the token alone, not (user, token): when a second
            // account signs in on the same handset the push service hands
            // back the same token, and a composite key would leave the first
            // account's row alive — their digest would land on the new
            // owner's lock screen. 255 because FCM tokens exceed 160.
            $table->string('push_token', 255)->unique();
            // The client-persisted install id, shared with the web push
            // subscription made from the same handset so the digest is not
            // delivered twice to one phone.
            $table->uuid('install_id')->nullable()->index();
            $table->string('device_name', 100)->nullable();
            $table->string('app_version', 32)->nullable();
            $table->string('os_version', 32)->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'platform']);
        });

        Schema::table('push_subscriptions', function (Blueprint $table): void {
            $table->uuid('install_id')->nullable()->index()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table): void {
            $table->dropColumn('install_id');
        });

        Schema::dropIfExists('devices');
    }
};
