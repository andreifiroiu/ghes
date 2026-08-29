<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_event_reactions', function (Blueprint $table) {
            // The effective, post-clamp profile change this reaction contributed,
            // keyed by profile key ("music", "tag:jazz"). Storing what was actually
            // applied — rather than the nominal config delta — keeps reversal exact
            // at the [0,1] clamp boundaries and survives EventMerger re-pointing the
            // row at a canonical event whose tags differ.
            //
            // Known imprecision: ProfileDecayer shrinks scores on a schedule, so a
            // delta reversed long after it was applied slightly over-corrects. The
            // error is bounded by the delta magnitude and always in the correct
            // direction; compensating would need a per-row decay generation counter.
            $table->json('applied_deltas')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_event_reactions', function (Blueprint $table) {
            $table->dropColumn('applied_deltas');
        });
    }
};
