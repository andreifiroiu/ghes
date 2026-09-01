<?php

declare(strict_types=1);

use App\Services\City\CityCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give `users.city` the covered city as its default and backfill the rows
     * that never got one.
     *
     * Nothing in the signup flow ever set a city: the onboarding chat was the
     * only writer, and its prompt never asks about it, so users landed on the
     * dashboard with a NULL city and an empty nearby-events filter.
     *
     * The default is baked in at migration time on purpose — Ghes covers one
     * city in this phase. Revisit when a second city ships; from then on the
     * city has to be chosen, not assumed.
     */
    public function up(): void
    {
        $label = CityCatalog::defaultLabel();

        Schema::table('users', function (Blueprint $table) use ($label) {
            $table->string('city')->nullable()->default($label)->change();
        });

        $backfilled = DB::table('users')
            ->whereNull('city')
            ->orWhereRaw('TRIM(city) = ?', [''])
            ->update(['city' => $label]);

        // A backfill that reports nothing is a backfill nobody can audit.
        Log::info('Backfilled the default city onto city-less accounts.', [
            'city' => $label,
            'rows' => $backfilled,
        ]);
    }

    /**
     * Drop the default only. Backfilled cities stay — they are real user data
     * now, and there is no way to tell them from a deliberate choice.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('city')->nullable()->default(null)->change();
        });
    }
};
