<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which provider account is which Ghes account. Linking by the
     * provider's stable subject rather than by email is what survives an
     * address change at the provider and Apple's private relay addresses.
     */
    public function up(): void
    {
        Schema::create('social_identities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 16);
            $table->string('subject', 255);
            // The address the provider reported when the link was made; Apple
            // sends it on the first sign-in only.
            $table->string('email', 255)->nullable();
            $table->timestamps();

            $table->unique(['provider', 'subject']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_identities');
    }
};
