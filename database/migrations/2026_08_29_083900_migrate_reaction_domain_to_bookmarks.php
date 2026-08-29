<?php

declare(strict_types=1);

use App\Console\Commands\MigrateReactionDomainCommand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration
{
    /**
     * Backfill for the reaction/bookmark split.
     *
     * The work lives in an idempotent artisan command so it can be tested
     * against seeded legacy rows and re-run by hand if a deploy is interrupted.
     */
    public function up(): void
    {
        Artisan::call(MigrateReactionDomainCommand::class);
    }

    /**
     * Irreversible by design: the `negtag:` suppression flags are destroyed, and
     * a bookmark can no longer be distinguished from the `saved` reaction it
     * came from once the split has happened.
     */
    public function down(): void {}
};
