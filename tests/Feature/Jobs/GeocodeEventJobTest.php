<?php

declare(strict_types=1);

use App\Jobs\EnrichEventJob;
use App\Jobs\GeocodeEventJob;
use App\Models\Event;
use App\Services\Processing\EventEnricher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

it('dispatches EnrichEventJob after geocoding', function () {
    Bus::fake([EnrichEventJob::class]);
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response([])]);

    $event = Event::factory()->create(['is_geocoded' => false]);

    (new GeocodeEventJob($event->id))->handle(app(EventEnricher::class));

    Bus::assertDispatched(EnrichEventJob::class, fn ($job) => $job->eventId === $event->id);
});
