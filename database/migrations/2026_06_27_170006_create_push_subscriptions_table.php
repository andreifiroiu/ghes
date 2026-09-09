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
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            // MySQL cannot put a unique index on TEXT without a prefix length;
            // 768 utf8mb4 chars is the widest single-column key InnoDB accepts
            // under ROW_FORMAT=DYNAMIC, its default since 5.7.9. A server left
            // on COMPACT caps keys at 767 bytes and rejects this at migrate
            // time — loudly, and only on MySQL, which prod is not.
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $table->string('endpoint', 768)->unique();
            } else {
                $table->text('endpoint')->unique();
            }
            $table->string('public_key')->nullable();
            $table->string('auth_token')->nullable();
            $table->string('content_encoding')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
