<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scraper_runs', function (Blueprint $table) {
            // The queue payload's UUID, which survives a retry. One logical
            // dispatch therefore owns one row no matter how many attempts it
            // takes, instead of leaving a fresh row behind on every retry.
            // Null for runs triggered synchronously from the CLI.
            //
            // Deliberately NOT unique: if a duplicate delivery arrives while
            // the first attempt is still alive, the orchestrator opens a second
            // row rather than clobbering a live one, and a unique index would
            // turn that safety valve into a constraint violation.
            $table->string('job_uuid', 36)->nullable()->after('city');
            $table->index(['job_uuid', 'started_at']);

            // Every query behind the admin scraper screens orders by
            // started_at; the existing indexes are all on created_at.
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('scraper_runs', function (Blueprint $table) {
            $table->dropIndex(['job_uuid', 'started_at']);
            $table->dropIndex(['started_at']);
            $table->dropColumn('job_uuid');
        });
    }
};
