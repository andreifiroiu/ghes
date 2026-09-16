<?php

declare(strict_types=1);

/**
 * The public pages each carry exactly one <h1>, and it says the same thing as
 * the server-rendered <title>.
 *
 * Asserted against the JSX source rather than a rendered page, because Ghes has
 * no SSR: the headings exist only after React hydrates, so no HTTP response in
 * this suite contains one. That makes this a source check by necessity. It
 * still earns its place — a second <h1> is exactly the kind of thing a layout
 * change adds silently, and Googlebot's rendered pass does see it.
 */
$page = static fn (string $path): string => (string) file_get_contents(resource_path('js/Pages/'.$path));

it('gives the landing page exactly one h1', function () use ($page) {
    expect(substr_count($page('Landing.jsx'), '<h1'))->toBe(1);
});

it('gives the event detail page exactly one h1', function () use ($page) {
    $source = $page('Events/Show.jsx');

    expect(substr_count($source, '<h1'))->toBe(1)
        // AppLayout renders its `title` prop as an h1, so a detail page that
        // passed one would have two.
        ->and($source)->toContain('<AppLayout>');
});

it('takes the browse page h1 from the layout, not a second heading', function () use ($page) {
    $source = $page('Events/Index.jsx');

    expect(substr_count($source, '<h1'))->toBe(0)
        ->and($source)->toContain('<AppLayout title={heading}>');
});

it('names the same heading in the browse h1 and its title tag', function () use ($page) {
    // The h1 and the <title> are built from the same string on both sides; a
    // crawler reading "Evenimente" in one and "Evenimente în Timișoara" in the
    // other sees a page disagreeing with itself.
    expect($page('Events/Index.jsx'))->toContain('`Evenimente în ${city}`');

    $this->withoutVite()
        ->get(route('events.index'))
        ->assertSee('<title inertia>Evenimente în Timișoara · Ghes</title>', escape: false);
});

it('renders the layout heading as an h1', function () {
    // The assertions above rely on AppLayout being where the h1 comes from.
    expect((string) file_get_contents(resource_path('js/Layouts/AppLayout.jsx')))
        ->toContain('<h1');
});
