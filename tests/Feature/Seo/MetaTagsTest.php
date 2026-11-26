<?php

declare(strict_types=1);

use App\Models\Event;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();
});

it('gives the landing page a title, description and canonical', function () {
    $html = (string) $this->get('/')->getContent();

    expect($html)
        ->toContain('<title inertia>'.e(config('eventpulse.seo.defaults.title')).'</title>')
        ->toContain('<meta name="description" content="')
        ->toContain('<link rel="canonical" href="'.route('home').'">');
});

it('does not suffix the brand onto the landing title, which already carries it', function () {
    $html = (string) $this->get('/')->getContent();

    expect($html)->not->toContain(e(config('eventpulse.seo.defaults.title')).e(config('eventpulse.seo.defaults.title_suffix')));
});

it('suffixes the brand onto a page that sets its own title', function () {
    $this->get(route('events.index'))
        ->assertSee('<title inertia>Evenimente în Timișoara · Ghes</title>', escape: false);
});

it('titles an event page with the event', function () {
    $event = Event::factory()->create(['title' => 'Concert de jazz la Capitol', 'starts_at' => now()->addWeek()]);

    $this->get(route('events.show', $event))
        ->assertSee('<title inertia>Concert de jazz la Capitol · Ghes</title>', escape: false);
});

it('describes an event page from the event description', function () {
    $event = Event::factory()->create([
        'description' => 'Un concert de jazz cu trupa locală.',
        'starts_at' => now()->addWeek(),
    ]);

    expect((string) $this->get(route('events.show', $event))->getContent())
        ->toContain('<meta name="description" content="Un concert de jazz cu trupa locală.">');
});

it('builds a description from the columns when the scraper captured none', function () {
    // Roughly a third of scraped listings arrive with no description at all.
    $event = Event::factory()->create([
        'title' => 'Seară de improvizație',
        'description' => null,
        'venue' => 'Teatrul Merlin',
        'starts_at' => now()->addWeek(),
    ]);

    expect((string) $this->get(route('events.show', $event))->getContent())
        ->toContain('Seară de improvizație')
        ->toContain('Teatrul Merlin');
});

it('strips markup out of a scraped description', function () {
    $event = Event::factory()->create([
        'description' => '<p>Concert <strong>excelent</strong>.</p>',
        'starts_at' => now()->addWeek(),
    ]);

    expect((string) $this->get(route('events.show', $event))->getContent())
        ->toContain('<meta name="description" content="Concert excelent.">');
});

it('escapes a description that contains quotes', function () {
    $event = Event::factory()->create([
        'description' => 'Spectacolul "Furtuna" de Shakespeare',
        'starts_at' => now()->addWeek(),
    ]);

    $html = (string) $this->get(route('events.show', $event))->getContent();

    // The attribute must not be closed early by the event's own text.
    expect($html)->toContain('&quot;Furtuna&quot;')
        ->and($html)->not->toContain('content="Spectacolul "Furtuna"');
});

it('points og:image at the event image', function () {
    $event = Event::factory()->create([
        'image_url' => 'https://cdn.example/poster.jpg',
        'starts_at' => now()->addWeek(),
    ]);

    expect((string) $this->get(route('events.show', $event))->getContent())
        ->toContain('<meta property="og:image" content="https://cdn.example/poster.jpg">');
});

it('falls back to an absolute default og:image', function () {
    // A relative og:image is silently dropped by every scraper that reads it.
    expect((string) $this->get('/')->getContent())
        ->toContain('<meta property="og:image" content="'.url(config('eventpulse.seo.defaults.image')).'">');
});

it('carries og and twitter cards on a public page', function () {
    $html = (string) $this->get(route('events.index'))->getContent();

    expect($html)
        ->toContain('<meta property="og:site_name" content="')
        ->toContain('<meta property="og:locale" content="ro_RO">')
        ->toContain('<meta property="og:url" content="'.route('events.index').'">')
        ->toContain('<meta name="twitter:card" content="summary_large_image">');
});

it('sets the document language from config rather than the app locale', function () {
    // APP_LOCALE is `ro` in .env while config/app.php defaults to `en`, so the
    // two disagree per host; og:locale is not a thing to let drift.
    expect((string) $this->get('/')->getContent())->toContain('<html lang="ro-RO">');
});

it('canonicalises a filtered browse page to the unfiltered one', function () {
    $html = (string) $this->get(route('events.index', ['category' => 'music']))->getContent();

    expect($html)->toContain('<link rel="canonical" href="'.route('events.index').'">');
});

it('does not carry one page\'s metadata into the next', function () {
    // The Pest client serves many requests from one container. Without the
    // per-request reset, page two inherits page one's title and every
    // assertion here passes for the wrong reason.
    $event = Event::factory()->create(['title' => 'Primul eveniment', 'starts_at' => now()->addWeek()]);

    $this->get(route('events.show', $event))->assertOk();

    $head = (string) str(
        (string) $this->get(route('events.index'))->getContent()
    )->between('<head>', '</head>');

    // The event's title legitimately appears in the browse page's ItemList, so
    // searching for it proves nothing. What must not survive is the detail
    // page's own metadata: its title, its canonical, and its Event node.
    expect($head)
        ->toContain('<title inertia>Evenimente în Timișoara · Ghes</title>')
        ->toContain('<link rel="canonical" href="'.route('events.index').'">')
        ->and($head)->not->toContain('"@type":"Event"')
        ->and($head)->not->toContain(route('events.show', $event).'#event');
});
