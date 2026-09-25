<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventSource;

it('recounts sources_count as distinct providers', function () {
    $migration = require database_path('migrations/2026_09_25_111431_recount_event_sources_by_provider.php');

    $oneProviderTwoUrls = Event::factory()->create(['sources_count' => 2]);
    EventSource::factory()->count(2)->forSource('iabilet')->create(['event_id' => $oneProviderTwoUrls->id]);

    $twoProviders = Event::factory()->create(['sources_count' => 2]);
    EventSource::factory()->count(2)->sequence(['source' => 'iabilet'], ['source' => 'allevents'])
        ->create(['event_id' => $twoProviders->id]);

    $noProvenance = Event::factory()->create(['sources_count' => 1]);

    $migration->up();

    expect($oneProviderTwoUrls->fresh()->sources_count)->toBe(1)
        ->and($twoProviders->fresh()->sources_count)->toBe(2)
        ->and($noProvenance->fresh()->sources_count)->toBe(1);
});
