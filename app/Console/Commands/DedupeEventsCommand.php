<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LogsConsoleOutput;
use App\DTOs\DuplicateGroup;
use App\Models\Event;
use App\Models\EventSource;
use App\Services\Processing\DuplicateFinder;
use App\Services\Processing\EventMerger;
use App\Services\Processing\EventTextNormalizer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Backfills the identity columns introduced with the canonical-event model,
 * then merges duplicates that are already in the database.
 *
 * Phase 0 must run immediately after the migrations: until every event has a
 * match_key and an event_sources row, the pipeline cannot recognise anything
 * imported before the change and would duplicate the whole back catalogue.
 *
 * What counts as a duplicate lives in DuplicateFinder, which the admin
 * review screen shares, so an operator reviewing groups by hand sees exactly
 * what this command would have merged on its own.
 */
class DedupeEventsCommand extends Command
{
    use LogsConsoleOutput;

    protected $signature = 'eventpulse:dedupe-events
        {--city= : Limit to one configured city key}
        {--since= : Only consider events starting on or after this date}
        {--backfill-only : Run phase 0 (key and source backfill) and stop}
        {--fuzzy : Also run the scored pass over same-day candidates}
        {--min-score= : Override eventpulse.dedup.min_score for this run}
        {--dry-run : Report what would change without writing anything}';

    protected $description = 'Backfill event identity keys and merge duplicate events';

    private bool $dryRun = false;

    public function handle(EventMerger $merger, DuplicateFinder $finder): int
    {
        $this->dryRun = (bool) $this->option('dry-run');

        if ($minScore = $this->option('min-score')) {
            config()->set('eventpulse.dedup.min_score', (float) $minScore);
        }

        // A dry run does the real work inside a transaction and rolls it back.
        // Phases 1 and 2 group on the keys phase 0 writes, so previewing them
        // without writing anything first would always report zero merges.
        if ($this->dryRun) {
            $this->warn('Dry run — every change below will be rolled back.');
            DB::beginTransaction();
        }

        try {
            $backfilled = $this->backfillIdentity($finder);
            $this->info("Phase 0: backfilled {$backfilled} events.");

            if ($this->option('backfill-only')) {
                return self::SUCCESS;
            }

            $merged = $this->mergeGroups($merger, $finder->byMatchKey($this->baseQuery()));
            $this->info("Phase 1: merged {$merged} duplicates on an exact key match.");

            if ($this->option('fuzzy')) {
                // Re-queried after phase 1, so events merged above are gone.
                $fuzzyMerged = $this->mergeGroups($merger, $finder->byScore($this->baseQuery()));
                $this->info("Phase 2: merged {$fuzzyMerged} duplicates on a scored match.");
            }

            return self::SUCCESS;
        } finally {
            if ($this->dryRun) {
                DB::rollBack();
                $this->warn('Dry run complete — nothing was written.');
            }
        }
    }

    /**
     * Phase 0 — give every event its derived identity columns and an
     * event_sources row describing where it originally came from.
     */
    private function backfillIdentity(DuplicateFinder $finder): int
    {
        $count = 0;

        $this->baseQuery()
            ->where(function ($query): void {
                $query->whereNull('match_key')->orWhereNull('city_slug');
            })
            ->orderBy('id')
            ->chunkById(200, function (Collection $events) use ($finder, &$count): void {
                /** @var Collection<int, Event> $events */
                foreach ($events as $event) {
                    $timezone = $finder->timezoneFor($event);

                    $localDate = $event->starts_at === null
                        ? null
                        : EventTextNormalizer::localDate($event->starts_at->toDateTimeString(), $timezone);

                    Event::withoutSyncingToSearch(function () use ($event, $localDate): void {
                        $event->forceFill([
                            'city_slug' => EventTextNormalizer::citySlug($event->city),
                            'local_date' => $localDate,
                            'match_key' => EventTextNormalizer::matchKey($event->title, $event->city, $localDate),
                        ])->save();
                    });

                    $this->backfillSourceRow($event, $localDate);

                    $count++;
                }
            });

        return $count;
    }

    /**
     * Recreate the event_sources row for an event imported before the table
     * existed, from the provider details stored on the event itself.
     */
    private function backfillSourceRow(Event $event, ?string $localDate): void
    {
        $occurrenceKey = EventTextNormalizer::occurrenceKey($localDate);
        $urlKey = EventTextNormalizer::normalizeUrl($event->source_url);

        $exists = EventSource::query()
            ->where('source', $event->source)
            ->where('url_key', $urlKey)
            ->where('occurrence_key', $occurrenceKey)
            ->exists();

        if ($exists) {
            return;
        }

        EventSource::create([
            'event_id' => $event->id,
            'source' => $event->source,
            'source_url' => $event->source_url,
            'url_key' => $urlKey,
            'source_id' => $event->source_id,
            'occurrence_key' => $occurrenceKey,
            'title' => $event->title,
            'starts_at' => $event->starts_at,
            'payload' => [],
            'first_seen_at' => $event->created_at ?? now(),
            'last_seen_at' => $event->updated_at ?? now(),
        ]);
    }

    /**
     * Fold every duplicate in each group into that group's best canonical.
     *
     * @param  Collection<int, DuplicateGroup>  $groups
     */
    private function mergeGroups(EventMerger $merger, Collection $groups): int
    {
        $merged = 0;

        foreach ($groups as $group) {
            $winner = $group->canonical();

            foreach ($group->duplicates() as $loser) {
                $this->reportMerge($winner, $loser, $group->score);

                $merger->mergeInto($winner, $loser, syncSearch: ! $this->dryRun);

                $merged++;
            }
        }

        return $merged;
    }

    private function reportMerge(Event $winner, Event $loser, float $score): void
    {
        $this->line(sprintf(
            '  merge [%.2f] "%s" (%s) <- "%s" (%s)',
            $score,
            $winner->title,
            $winner->source,
            $loser->title,
            $loser->source,
        ));
    }

    /**
     * @return Builder<Event>
     */
    private function baseQuery(): Builder
    {
        $query = Event::query();

        if ($city = $this->option('city')) {
            $label = (string) config("eventpulse.cities.{$city}.label", (string) $city);
            $query->where('city_slug', EventTextNormalizer::citySlug($label));
        }

        if ($since = $this->option('since')) {
            $query->where('starts_at', '>=', $since);
        }

        return $query;
    }
}
