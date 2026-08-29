<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LogsConsoleOutput;
use App\Enums\Reaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One-off backfill for the reaction/bookmark split (2026-08-29).
 *
 * Idempotent and re-runnable, and driver-portable (pure PHP/Eloquent, no JSONB
 * operators) because tests run on SQLite while production is PostgreSQL. Lives
 * as a command rather than inline migration closures so the data transformation
 * is testable and ops has a re-run handle.
 */
class MigrateReactionDomainCommand extends Command
{
    use LogsConsoleOutput;

    protected $signature = 'eventpulse:migrate-reaction-domain';

    protected $description = 'Split saved reactions into bookmarks, collapse hidden into not_interested, and strip negative tags';

    public function handle(): int
    {
        $this->info('Bookmarks created: '.$this->migrateSavedToBookmarks());
        $this->info('Negative reactions collapsed: '.$this->collapseNegatives());
        $this->info('Profiles cleaned: '.$this->stripNegativeTags());

        return self::SUCCESS;
    }

    /**
     * Move `saved` reactions into event_bookmarks.
     *
     * The bookmark inherits the reaction's timestamps and is marked processed:
     * the old `saved` delta is already in the user's profile, so re-applying it
     * would double-count. applied_deltas stays null, which the reversal path
     * treats as "unknown provenance, nothing to reverse".
     */
    private function migrateSavedToBookmarks(): int
    {
        $created = 0;

        DB::table('user_event_reactions')
            ->where('reaction', 'saved')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$created): void {
                foreach ($rows as $row) {
                    $exists = DB::table('event_bookmarks')
                        ->where('user_id', $row->user_id)
                        ->where('event_id', $row->event_id)
                        ->exists();

                    if (! $exists) {
                        DB::table('event_bookmarks')->insert([
                            'id' => (string) Str::uuid(),
                            'user_id' => $row->user_id,
                            'event_id' => $row->event_id,
                            'applied_deltas' => null,
                            'is_processed' => true,
                            'created_at' => $row->created_at,
                            'updated_at' => $row->updated_at,
                        ]);
                        $created++;
                    }

                    DB::table('user_event_reactions')->where('id', $row->id)->delete();
                }
            });

        return $created;
    }

    /**
     * Collapse the retired `hidden` reaction into `not_interested`, and drop the
     * retired `link_opened` (nothing ever wrote it, but an enum cast would fail
     * on a stray row).
     *
     * Converted rows keep applied_deltas = null, so the old -0.25/-0.30 stays
     * baked into the profile and is left to decay rather than being guessed at.
     */
    private function collapseNegatives(): int
    {
        $collapsed = DB::table('user_event_reactions')
            ->where('reaction', 'hidden')
            ->update(['reaction' => Reaction::NotInterested->value]);

        DB::table('discovery_logs')
            ->where('outcome', 'hidden')
            ->update(['outcome' => Reaction::NotInterested->value]);

        DB::table('user_event_reactions')->where('reaction', 'link_opened')->delete();
        DB::table('discovery_logs')->where('outcome', 'link_opened')->update(['outcome' => null]);

        return $collapsed;
    }

    /**
     * Remove every `negtag:` key from users' interest profiles.
     *
     * These were permanent, non-decaying tag blacklists written by the retired
     * "Ascunde" reaction, with no UI to inspect or clear them.
     */
    private function stripNegativeTags(): int
    {
        $cleaned = 0;

        DB::table('users')
            ->whereNotNull('interest_profile')
            ->orderBy('id')
            ->chunkById(200, function ($users) use (&$cleaned): void {
                foreach ($users as $user) {
                    $profile = json_decode((string) $user->interest_profile, true);

                    if (! is_array($profile)) {
                        continue;
                    }

                    $clean = array_filter(
                        $profile,
                        fn ($key) => ! str_starts_with((string) $key, 'negtag:'),
                        ARRAY_FILTER_USE_KEY,
                    );

                    if (count($clean) === count($profile)) {
                        continue;
                    }

                    DB::table('users')
                        ->where('id', $user->id)
                        ->update(['interest_profile' => json_encode((object) $clean)]);

                    $cleaned++;
                }
            });

        return $cleaned;
    }
}
