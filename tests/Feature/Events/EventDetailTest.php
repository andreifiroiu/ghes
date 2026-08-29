<?php

declare(strict_types=1);

use App\Enums\EventCategory;
use App\Models\Event;
use App\Models\EventSource;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->withoutVite();
});

it('shows related events on the detail page', function () {
    $event = Event::factory()->create([
        'category' => EventCategory::Music,
        'tags' => ['jazz'],
        'starts_at' => now()->addDays(3),
    ]);

    $related = Event::factory()->create([
        'category' => EventCategory::Music,
        'tags' => ['jazz'],
        'starts_at' => now()->addDays(4),
    ]);

    $this->get("/events/{$event->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Events/Show')
            ->where('event.id', $event->id)
            ->has('relatedEvents', 1)
            ->where('relatedEvents.0.id', $related->id)
        );
});

it('renders an empty related list rather than failing when nothing matches', function () {
    $event = Event::factory()->create([
        'category' => EventCategory::Music,
        'tags' => ['jazz'],
        'venue' => 'Casa Tineretului',
        'starts_at' => now()->addDays(3),
    ]);

    $this->get("/events/{$event->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Events/Show')
            ->has('relatedEvents', 0)
        );
});

it('exposes the coordinates the detail page maps with', function () {
    $event = Event::factory()->create([
        'latitude' => 45.7489,
        'longitude' => 21.2087,
        'starts_at' => now()->addDays(3),
    ]);

    $this->get("/events/{$event->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('event.latitude', 45.7489)
            ->where('event.longitude', 21.2087)
        );
});

it('lists every provider that reported the event, for guests too', function () {
    $event = Event::factory()->create(['starts_at' => now()->addDays(3)]);

    EventSource::factory()->create([
        'event_id' => $event->id,
        'source' => 'iabilet',
        'source_url' => 'https://m.iabilet.ro/a',
    ]);
    EventSource::factory()->create([
        'event_id' => $event->id,
        'source' => 'zilesinopti',
        'source_url' => 'https://zilesinopti.ro/b',
    ]);

    $this->get("/events/{$event->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('event.sources', 2)
            ->where('event.sources.0.source', 'iabilet')
            ->where('event.sources.1.source', 'zilesinopti')
        );
});

it('deduplicates providers that contributed the same URL twice', function () {
    $event = Event::factory()->create(['starts_at' => now()->addDays(3)]);

    // Merging two occurrences of one listing repoints both event_sources rows
    // onto the survivor; they differ only by occurrence_key.
    foreach (['2026-09-01', '2026-09-02'] as $occurrence) {
        EventSource::factory()->create([
            'event_id' => $event->id,
            'source' => 'iabilet',
            'source_url' => 'https://m.iabilet.ro/same',
            'occurrence_key' => $occurrence,
        ]);
    }

    $this->get("/events/{$event->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->has('event.sources', 1));
});

it('does not leak reaction state into a guest related list', function () {
    $event = Event::factory()->create([
        'category' => EventCategory::Music,
        'tags' => ['jazz'],
        'starts_at' => now()->addDays(3),
    ]);
    Event::factory()->create([
        'category' => EventCategory::Music,
        'tags' => ['jazz'],
        'starts_at' => now()->addDays(4),
    ]);

    $this->get("/events/{$event->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('relatedEvents', 1)
            ->missing('relatedEvents.0.current_reaction')
        );
});

it('returns related events from the API detail endpoint too', function () {
    $user = User::factory()->create();

    $event = Event::factory()->create([
        'category' => EventCategory::Music,
        'tags' => ['jazz'],
        'starts_at' => now()->addDays(3),
    ]);
    $related = Event::factory()->create([
        'category' => EventCategory::Music,
        'tags' => ['jazz'],
        'starts_at' => now()->addDays(4),
    ]);

    $response = $this->actingAs($user)->getJson("/api/events/{$event->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $event->id)
        ->assertJsonPath('relatedEvents.0.id', $related->id);
});

describe('calendar download', function () {
    it('serves the event as an iCalendar attachment', function () {
        $event = Event::factory()->create([
            'title' => 'Jazz în Parc',
            'venue' => 'Parcul Central',
            'starts_at' => now()->addDays(3)->setTime(19, 30),
            'ends_at' => now()->addDays(3)->setTime(22, 0),
        ]);

        $response = $this->get("/events/{$event->id}/calendar.ics");

        $response->assertOk();
        expect($response->headers->get('content-type'))->toContain('text/calendar');
        expect($response->headers->get('content-disposition'))->toContain('attachment');

        $body = $response->getContent();

        expect($body)
            ->toContain('BEGIN:VCALENDAR')
            ->toContain('BEGIN:VEVENT')
            ->toContain('END:VCALENDAR')
            ->toContain('UID:'.$event->id.'@')
            ->toContain('DTSTART:'.$event->starts_at->utc()->format('Ymd\THis\Z'))
            ->toContain('DTEND:'.$event->ends_at->utc()->format('Ymd\THis\Z'))
            ->toContain('SUMMARY:Jazz în Parc');
    });

    it('gives an event with no end a default duration', function () {
        $event = Event::factory()->create([
            'starts_at' => now()->addDays(3)->setTime(19, 0),
            'ends_at' => null,
        ]);

        $body = $this->get("/events/{$event->id}/calendar.ics")->getContent();

        expect($body)->toContain(
            'DTEND:'.$event->starts_at->copy()->addHours(2)->utc()->format('Ymd\THis\Z')
        );
    });

    it('escapes characters that would otherwise break the format', function () {
        $event = Event::factory()->create([
            'title' => 'Rock, Pop; and more',
            'description' => "First line\nSecond line",
            'starts_at' => now()->addDays(3),
        ]);

        $body = $this->get("/events/{$event->id}/calendar.ics")->getContent();

        expect($body)
            ->toContain('SUMMARY:Rock\, Pop\; and more')
            ->toContain('First line\nSecond line');
    });

    it('folds long lines to 75 octets', function () {
        $event = Event::factory()->create([
            'title' => str_repeat('a', 300),
            'description' => null,
            'starts_at' => now()->addDays(3),
        ]);

        $body = $this->get("/events/{$event->id}/calendar.ics")->getContent();

        foreach (explode("\r\n", $body) as $line) {
            expect(strlen($line))->toBeLessThanOrEqual(75);
        }
    });

    it('refuses to invent a date for an undated event', function () {
        $event = Event::factory()->create(['starts_at' => null, 'ends_at' => null]);

        $this->get("/events/{$event->id}/calendar.ics")->assertNotFound();
    });

    it('ignores an end time that is not after the start', function () {
        $event = Event::factory()->create([
            'starts_at' => now()->addDays(3)->setTime(22, 0),
            // "22:00–02:00" with the end date never rolled to the next day —
            // the shape several scrapers produce.
            'ends_at' => now()->addDays(3)->setTime(2, 0),
        ]);

        $body = $this->get("/events/{$event->id}/calendar.ics")->getContent();

        expect($body)->toContain(
            'DTEND:'.$event->starts_at->copy()->addHours(2)->utc()->format('Ymd\THis\Z')
        );
    });

    it('emits the ticket URL verbatim, not text-escaped', function () {
        $event = Event::factory()->create([
            'source_url' => 'https://allevents.in/e?ids=1,2;x=3',
            'starts_at' => now()->addDays(3),
        ]);

        $body = $this->get("/events/{$event->id}/calendar.ics")->getContent();

        expect($body)->toContain('URL:https://allevents.in/e?ids=1,2;x=3')
            ->not->toContain('ids=1\,2');
    });

    it('transliterates Romanian diacritics in the download filename', function () {
        $event = Event::factory()->create([
            'title' => 'Concert în Piață',
            'starts_at' => now()->addDays(3),
        ]);

        $disposition = $this->get("/events/{$event->id}/calendar.ics")
            ->headers->get('content-disposition');

        expect($disposition)->toContain('concert-in-piata.ics');
    });

    it('404s for an admin-hidden event', function () {
        $event = Event::factory()->create([
            'is_hidden' => true,
            'starts_at' => now()->addDays(3),
        ]);

        $this->get("/events/{$event->id}/calendar.ics")->assertNotFound();
    });

    it('follows a merged duplicate to the surviving event', function () {
        $canonical = Event::factory()->create([
            'title' => 'Survivor',
            'starts_at' => now()->addDays(3),
        ]);
        $duplicate = Event::factory()->merged($canonical)->create();

        $body = $this->get("/events/{$duplicate->id}/calendar.ics")->getContent();

        expect($body)->toContain('UID:'.$canonical->id.'@')
            ->toContain('SUMMARY:Survivor');
    });
});
