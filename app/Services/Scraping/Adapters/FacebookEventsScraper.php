<?php

declare(strict_types=1);

namespace App\Services\Scraping\Adapters;

use App\Contracts\ScraperAdapter;
use App\DTOs\RawEvent;
use App\Models\ApifyUsageLog;
use App\Services\Processing\EventTextNormalizer;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class FacebookEventsScraper implements ScraperAdapter
{
    private const string SOURCE = 'facebook_events';

    private const string APIFY_BASE = 'https://api.apify.com/v2';

    /** Seconds to wait between Apify polling requests. */
    private const int POLL_INTERVAL_SECONDS = 10;

    /** Maximum number of poll attempts before giving up (5 minutes). */
    private const int MAX_POLL_ATTEMPTS = 30;

    public function adapterKey(): string
    {
        return self::SOURCE;
    }

    public function sourceIdentifier(array $sourceConfig): string
    {
        return self::SOURCE.'@facebook.com';
    }

    /**
     * @param  array{adapter: string, params?: array<string, mixed>, enabled: bool, interval_hours: int}  $sourceConfig
     * @param  array{label: string, timezone: string, coordinates: list<float>, radius_km: int}  $cityConfig
     * @param  callable(RawEvent): void  $onEvent
     */
    public function scrape(array $sourceConfig, array $cityConfig, callable $onEvent): void
    {
        /** @var Collection<int, RawEvent> $allEvents */
        $allEvents = collect();

        $params = $sourceConfig['params'] ?? [];

        // Strategy A: Apify (most reliable, paid)
        if ($this->isApifyConfigured()) {
            try {
                $apifyEvents = $this->scrapeViaApify($sourceConfig, $cityConfig);
                $allEvents = $allEvents->merge($apifyEvents);
                Log::info('FacebookEventsScraper: Apify returned events', ['count' => $apifyEvents->count()]);
            } catch (\Throwable $e) {
                Log::warning('FacebookEventsScraper: Apify strategy failed', ['error' => $e->getMessage()]);
            }
        }

        // Strategy B: npm facebook-event-scraper (free, fragile)
        if ($params['npm_scraper_enabled'] ?? false) {
            try {
                $npmEvents = $this->scrapeViaNpmPackage($sourceConfig, $cityConfig);
                $allEvents = $allEvents->merge($npmEvents);
                Log::info('FacebookEventsScraper: npm scraper returned events', ['count' => $npmEvents->count()]);
            } catch (\Throwable $e) {
                Log::warning('FacebookEventsScraper: npm strategy failed', ['error' => $e->getMessage()]);
            }
        }

        $deduplicated = $this->deduplicateByFingerprint($allEvents);

        $emitted = 0;
        foreach ($deduplicated as $event) {
            $onEvent($event);
            $emitted++;
        }

        Log::info('FacebookEventsScraper: scrape complete', ['emitted' => $emitted]);
    }

    // -------------------------------------------------------------------------
    // Strategy A — Apify
    // -------------------------------------------------------------------------

    /**
     * Fetch events for every configured query via the Apify Facebook Events actor.
     *
     * @param  array{adapter: string, params?: array<string, mixed>, enabled: bool, interval_hours: int}  $sourceConfig
     * @param  array{label: string, timezone: string, coordinates: list<float>, radius_km: int}  $cityConfig
     * @return Collection<int, RawEvent>
     */
    private function scrapeViaApify(array $sourceConfig, array $cityConfig): Collection
    {
        $token = (string) config('eventpulse.apify_api_token', '');
        $params = $sourceConfig['params'] ?? [];

        $actorId = (string) ($params['apify_actor'] ?? 'apify/facebook-events-scraper');

        $queries = is_array($params['apify_queries'] ?? null) ? $params['apify_queries'] : [];

        /** @var Collection<int, RawEvent> $allEvents */
        $allEvents = collect();

        foreach ($queries as $query) {
            if (! $this->hasDailyBudgetRemaining()) {
                Log::warning('FacebookEventsScraper: Apify daily budget exceeded, skipping remaining queries');
                break;
            }

            $events = $this->runApifyQuery($actorId, $token, (string) $query, $cityConfig);

            if ($events !== null) {
                $allEvents = $allEvents->merge($events);
            }
        }

        return $allEvents;
    }

    /**
     * Start an Apify actor run for one query, poll until complete, and fetch results.
     *
     * Returns null when the run cannot be started or does not succeed.
     *
     * @param  array{label: string, timezone: string, coordinates: list<float>, radius_km: int}  $cityConfig
     * @return Collection<int, RawEvent>|null
     */
    private function runApifyQuery(
        string $actorId,
        string $token,
        string $query,
        array $cityConfig,
    ): ?Collection {
        // 1. Start the run
        $startResponse = Http::withToken($token)->timeout(30)->post(
            self::APIFY_BASE."/acts/{$actorId}/runs",
            ['searchQueries' => [$query], 'maxResults' => 100],
        );

        if ($startResponse->failed()) {
            Log::warning('FacebookEventsScraper: Failed to start Apify run', [
                'query' => $query,
                'status' => $startResponse->status(),
            ]);

            return null;
        }

        $runId = $startResponse->json('data.id');

        if (! is_string($runId) || $runId === '') {
            Log::warning('FacebookEventsScraper: Apify start response missing run ID', ['query' => $query]);

            return null;
        }

        // 2. Poll until complete
        $runData = $this->pollUntilDone($runId, $token);

        if ($runData === null) {
            Log::warning('FacebookEventsScraper: Apify run timed out', ['run_id' => $runId, 'query' => $query]);

            return null;
        }

        $finalStatus = (string) ($runData['status'] ?? '');

        if ($finalStatus !== 'SUCCEEDED') {
            Log::warning('FacebookEventsScraper: Apify run did not succeed', [
                'run_id' => $runId,
                'status' => $finalStatus,
                'query' => $query,
            ]);
            $this->logApifyUsage($actorId, $runId, $query, $runData, 0);

            return null;
        }

        // 3. Fetch dataset items
        $itemsResponse = Http::withToken($token)->timeout(60)->get(
            self::APIFY_BASE."/actor-runs/{$runId}/dataset/items",
        );

        if ($itemsResponse->failed()) {
            Log::warning('FacebookEventsScraper: Failed to fetch Apify dataset', ['run_id' => $runId]);

            return null;
        }

        $items = $itemsResponse->json();

        if (! is_array($items)) {
            return collect();
        }

        /** @var Collection<int, RawEvent> $events */
        $events = collect();

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $event = $this->mapApifyEvent($item, $cityConfig['label']);
            if ($event !== null) {
                $events->push($event);
            }
        }

        $this->logApifyUsage($actorId, $runId, $query, $runData, $events->count());

        return $events;
    }

    /**
     * Poll the Apify run status until it reaches a terminal state or we time out.
     *
     * Returns the run's data array on success/failure, or null when we time out.
     *
     * @return array<string, mixed>|null
     */
    private function pollUntilDone(string $runId, string $token): ?array
    {
        for ($attempt = 0; $attempt < self::MAX_POLL_ATTEMPTS; $attempt++) {
            $this->sleepBetweenPolls(self::POLL_INTERVAL_SECONDS);

            $response = Http::withToken($token)->timeout(30)->get(
                self::APIFY_BASE."/actor-runs/{$runId}",
            );

            if ($response->failed()) {
                return null;
            }

            $data = $response->json('data');

            if (! is_array($data)) {
                return null;
            }

            $status = (string) ($data['status'] ?? '');

            if (in_array($status, ['SUCCEEDED', 'FAILED', 'ABORTED', 'TIMED-OUT'], true)) {
                return $data;
            }

            // RUNNING or READY — keep polling
        }

        return null; // Our own poll timeout
    }

    /**
     * Persist an Apify run record for cost tracking.
     *
     * @param  array<string, mixed>  $runData
     */
    private function logApifyUsage(
        string $actorId,
        string $runId,
        string $query,
        array $runData,
        int $eventsReturned,
    ): void {
        try {
            $costUsd = is_numeric($runData['usageTotalCostUsd'] ?? null)
                ? (float) $runData['usageTotalCostUsd']
                : null;

            $durationMs = is_int($runData['stats']['durationMillis'] ?? null)
                ? (int) $runData['stats']['durationMillis']
                : null;

            ApifyUsageLog::create([
                'actor_id' => $actorId,
                'run_id' => $runId,
                'query' => $query,
                'events_returned' => $eventsReturned,
                'cost_usd' => $costUsd,
                'duration_seconds' => $durationMs !== null ? (int) round($durationMs / 1000) : null,
                'status' => (string) ($runData['status'] ?? 'UNKNOWN'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('FacebookEventsScraper: Failed to log Apify usage', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Map a single Apify actor result item to a RawEvent.
     *
     * Returns null when the event is outside the target city or has no title/URL.
     *
     * @param  array<string, mixed>  $item
     */
    private function mapApifyEvent(array $item, string $cityLabel): ?RawEvent
    {
        $title = trim((string) ($item['name'] ?? ''));
        if ($title === '') {
            return null;
        }

        $sourceUrl = trim((string) ($item['url'] ?? ''));
        if ($sourceUrl === '') {
            return null;
        }

        // City filter — skip events not in the target city
        $location = is_array($item['location'] ?? null) ? $item['location'] : [];
        if (! $this->isInCity($location, $cityLabel)) {
            return null;
        }

        $startsAt = $this->parseFacebookDate((string) ($item['startDate'] ?? ''));
        $endsAt = isset($item['endDate']) ? $this->parseFacebookDate((string) $item['endDate']) : null;

        $venue = trim((string) ($location['name'] ?? '')) ?: null;
        $address = trim((string) ($location['address'] ?? '')) ?: null;

        $description = trim((string) ($item['description'] ?? ''));
        $description = $description !== '' ? $description : null;

        $imageUrl = trim((string) ($item['image'] ?? '')) ?: null;

        $usersGoing = (int) ($item['usersGoing'] ?? 0);
        $usersInterested = (int) ($item['usersInterested'] ?? 0);
        $organizerName = trim((string) ($item['organizerName'] ?? '')) ?: null;

        return new RawEvent(
            title: $title,
            description: $description,
            sourceUrl: $sourceUrl,
            sourceId: null,
            source: self::SOURCE,
            venue: $venue,
            address: $address,
            city: $cityLabel,
            startsAt: $startsAt,
            endsAt: $endsAt,
            priceMin: null,
            priceMax: null,
            currency: null,
            isFree: null,
            imageUrl: $imageUrl,
            metadata: [
                'users_going' => $usersGoing,
                'users_interested' => $usersInterested,
                'popularity_score' => $this->calculatePopularityScore($usersGoing, $usersInterested),
                'organizer' => $organizerName,
                'facebook_url' => $sourceUrl,
                'source_strategy' => 'apify',
            ],
        );
    }

    // -------------------------------------------------------------------------
    // Strategy B — npm facebook-event-scraper
    // -------------------------------------------------------------------------

    /**
     * Fetch events by running the facebook-event-scraper Node.js bridge script.
     *
     * @param  array{adapter: string, params?: array<string, mixed>, enabled: bool, interval_hours: int}  $sourceConfig
     * @param  array{label: string, timezone: string, coordinates: list<float>, radius_km: int}  $cityConfig
     * @return Collection<int, RawEvent>
     */
    private function scrapeViaNpmPackage(array $sourceConfig, array $cityConfig): Collection
    {
        $params = $sourceConfig['params'] ?? [];
        $pages = is_array($params['facebook_pages'] ?? null) ? $params['facebook_pages'] : [];

        if ($pages === []) {
            Log::debug('FacebookEventsScraper: npm strategy skipped — no facebook_pages configured');

            return collect();
        }

        $input = json_encode(['pages' => $pages]);

        if ($input === false) {
            return collect();
        }

        $result = $this->runProcess(
            ['node', base_path('scripts/facebook-scraper.js'), $input],
            120,
        );

        if (! $result['successful']) {
            Log::warning('FacebookEventsScraper: npm script failed', ['stderr' => $result['error']]);

            return collect();
        }

        $rawOutput = trim($result['output']);

        if ($rawOutput === '') {
            return collect();
        }

        $items = json_decode($rawOutput, true);

        if (! is_array($items)) {
            Log::warning('FacebookEventsScraper: npm script produced invalid JSON');

            return collect();
        }

        /** @var Collection<int, RawEvent> $events */
        $events = collect();

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $event = $this->mapNpmEvent($item, $cityConfig['label']);
            if ($event !== null) {
                $events->push($event);
            }
        }

        return $events;
    }

    /**
     * Map a single facebook-event-scraper npm package result to a RawEvent.
     *
     * The npm package returns: id, name, description, startTimestamp, endTimestamp,
     * location (object with name, description, url), photo (imageUri), ticketUrl,
     * hosts, usersGoing, usersInterested.
     *
     * @param  array<string, mixed>  $item
     */
    private function mapNpmEvent(array $item, string $cityLabel): ?RawEvent
    {
        $title = trim((string) ($item['name'] ?? ''));
        if ($title === '') {
            return null;
        }

        $eventId = isset($item['id']) ? (string) $item['id'] : null;

        $sourceUrl = $eventId !== null
            ? "https://www.facebook.com/events/{$eventId}/"
            : '';

        if ($sourceUrl === '') {
            return null;
        }

        $startsAt = null;
        if (is_numeric($item['startTimestamp'] ?? null)) {
            try {
                $startsAt = Carbon::createFromTimestamp((int) $item['startTimestamp'])->utc()->toDateTimeString();
            } catch (\Throwable) {
            }
        }

        $endsAt = null;
        if (is_numeric($item['endTimestamp'] ?? null)) {
            try {
                $endsAt = Carbon::createFromTimestamp((int) $item['endTimestamp'])->utc()->toDateTimeString();
            } catch (\Throwable) {
            }
        }

        $venue = null;
        $address = null;
        if (is_array($item['location'] ?? null)) {
            $venue = trim((string) ($item['location']['name'] ?? '')) ?: null;
            $address = trim((string) ($item['location']['description'] ?? '')) ?: null;
        }

        $imageUrl = null;
        if (is_array($item['photo'] ?? null)) {
            $imageUrl = trim((string) ($item['photo']['imageUri'] ?? '')) ?: null;
        }

        $description = trim((string) ($item['description'] ?? ''));
        $description = $description !== '' ? $description : null;

        $usersGoing = (int) ($item['usersGoing'] ?? 0);
        $usersInterested = (int) ($item['usersInterested'] ?? 0);

        $hosts = is_array($item['hosts'] ?? null) ? $item['hosts'] : [];
        $organizerName = null;
        if ($hosts !== [] && is_array($hosts[0] ?? null)) {
            $organizerName = trim((string) ($hosts[0]['name'] ?? '')) ?: null;
        }

        return new RawEvent(
            title: $title,
            description: $description,
            sourceUrl: $sourceUrl,
            sourceId: $eventId,
            source: self::SOURCE,
            venue: $venue,
            address: $address,
            city: $cityLabel,
            startsAt: $startsAt,
            endsAt: $endsAt,
            priceMin: null,
            priceMax: null,
            currency: null,
            isFree: null,
            imageUrl: $imageUrl,
            metadata: [
                'users_going' => $usersGoing,
                'users_interested' => $usersInterested,
                'popularity_score' => $this->calculatePopularityScore($usersGoing, $usersInterested),
                'organizer' => $organizerName,
                'facebook_url' => $sourceUrl,
                'source_strategy' => 'npm',
            ],
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Deduplicate a mixed collection of events by title + date + venue fingerprint.
     *
     * @param  Collection<int, RawEvent>  $events
     * @return Collection<int, RawEvent>
     */
    private function deduplicateByFingerprint(Collection $events): Collection
    {
        /** @var Collection<int, RawEvent> $unique */
        $unique = collect();
        /** @var array<int, string> $seen */
        $seen = [];

        foreach ($events as $event) {
            $fp = $this->fingerprint($event->title, $event->startsAt, $event->venue);

            if (! in_array($fp, $seen, strict: true)) {
                $seen[] = $fp;
                $unique->push($event);
            }
        }

        return $unique;
    }

    /**
     * Compute a SHA-256 deduplication fingerprint from normalised event fields.
     */
    private function fingerprint(string $title, ?string $date, ?string $venue): string
    {
        return hash('sha256', implode('|', [
            $this->normalizeText($title),
            $this->normalizeText($date ?? ''),
            $this->normalizeText($venue ?? ''),
        ]));
    }

    /**
     * Popularity score: log₂(usersGoing + usersInterested × 0.5 + 1).
     *
     * Returns 0.0 for events with no engagement data.
     */
    private function calculatePopularityScore(int $usersGoing, int $usersInterested): float
    {
        return round(log($usersGoing + $usersInterested * 0.5 + 1, 2), 4);
    }

    /**
     * Check whether an Apify location object refers to the target city.
     *
     * @param  array<string, mixed>  $location
     */
    private function isInCity(array $location, string $cityLabel): bool
    {
        $cityName = trim((string) ($location['city'] ?? ''));
        $address = trim((string) ($location['address'] ?? ''));
        $needle = $this->normalizeText($cityLabel);

        return str_contains($this->normalizeText($cityName), $needle)
            || str_contains($this->normalizeText($address), $needle);
    }

    /**
     * Parse a Facebook/Apify ISO 8601 date string to UTC.
     *
     * Example: "2026-04-23T19:00:00.000Z" → "2026-04-23 19:00:00"
     */
    private function parseFacebookDate(string $dateStr): ?string
    {
        if ($dateStr === '') {
            return null;
        }

        try {
            return Carbon::parse($dateStr)->utc()->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Lowercase and strip Romanian diacritics for fuzzy matching.
     *
     * Delegates to the shared normaliser: this adapter implements
     * ScraperAdapter directly rather than extending AbstractHtmlScraper, so
     * without this it would drift from the rest of the pipeline.
     */
    private function normalizeText(string $text): string
    {
        return EventTextNormalizer::normalizeText($text);
    }

    /**
     * Return true when there is budget remaining for Apify runs today.
     *
     * Override in tests to control budget behaviour without a database.
     */
    protected function hasDailyBudgetRemaining(): bool
    {
        $budget = (float) config('eventpulse.apify_daily_budget_usd', 5.0);
        $spent = (float) ApifyUsageLog::whereDate('created_at', today())->sum('cost_usd');

        return $spent < $budget;
    }

    /**
     * Return true when an Apify API token is configured.
     */
    protected function isApifyConfigured(): bool
    {
        return (string) config('eventpulse.apify_api_token', '') !== '';
    }

    /**
     * Run the Node.js facebook-scraper bridge script.
     *
     * Override in tests to avoid spawning a real process.
     *
     * @param  list<string>  $command
     * @return array{successful: bool, output: string, error: string}
     */
    protected function runProcess(array $command, int $timeout): array
    {
        $process = new Process($command);
        $process->setTimeout($timeout);
        $process->run();

        return [
            'successful' => $process->isSuccessful(),
            'output' => $process->getOutput(),
            'error' => $process->getErrorOutput(),
        ];
    }

    /**
     * Pause between Apify poll requests. Override in tests to skip delays.
     */
    protected function sleepBetweenPolls(int $seconds): void
    {
        sleep($seconds);
    }
}
