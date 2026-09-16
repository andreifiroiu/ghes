<?php

declare(strict_types=1);

use App\Services\Seo\RobotsTxt;

it('serves robots.txt as plain text', function () {
    $this->get('/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8');
});

it('disallows everything on a host that is not the public one', function () {
    config(['app.env' => 'production', 'app.url' => 'https://staging.ghes.ro']);

    $body = $this->get('/robots.txt')->getContent();

    expect($body)->toContain('Disallow: /')
        ->and($body)->not->toContain('Sitemap:');
});

it('disallows everything outside production even on the public host', function () {
    config(['app.env' => 'local', 'app.url' => 'https://ghes.ro']);

    expect($this->get('/robots.txt')->getContent())->not->toContain('Sitemap:');
});

it('publishes the full policy on the production host', function () {
    config([
        'app.env' => 'production',
        'app.url' => 'https://ghes.ro',
        'eventpulse.seo.robots.public_hosts' => ['ghes.ro'],
    ]);

    $body = (string) $this->get('/robots.txt')->getContent();

    expect($body)
        ->toContain('Sitemap: https://ghes.ro/sitemap.xml')
        ->toContain('Disallow: /admin/')
        // Answer engines get the public rules, not a blanket block.
        ->toContain('User-agent: ClaudeBot')
        ->toContain('User-agent: PerplexityBot')
        // Training-only collectors are shut out.
        ->toContain('User-agent: CCBot');
});

it('gives the training-only crawlers a blanket disallow, not the public rules', function () {
    config([
        'app.env' => 'production',
        'app.url' => 'https://ghes.ro',
        'eventpulse.seo.robots.public_hosts' => ['ghes.ro'],
    ]);

    $body = (string) $this->get('/robots.txt')->getContent();

    // The block group is the last User-agent stanza before the Sitemap line,
    // and the only `Disallow: /` in a public policy belongs to it.
    $blockStanza = substr($body, (int) strpos($body, 'User-agent: Google-Extended'));

    expect($blockStanza)->toContain('Disallow: /')
        ->and(substr_count($body, 'Disallow: /'."\n"))->toBe(1);
});

it('matches the public host as a whole host, not as a prefix', function () {
    config([
        'app.env' => 'production',
        'app.url' => 'https://ghes.ro.evil.example',
        'eventpulse.seo.robots.public_hosts' => ['ghes.ro'],
    ]);

    expect($this->get('/robots.txt')->getContent())->not->toContain('Sitemap:');
});

it('reads the host out of APP_URL regardless of scheme or path', function () {
    config(['app.url' => 'HTTP://Ghes.ro/some/path']);

    expect(RobotsTxt::fromConfig()->host())->toBe('ghes.ro');
});

it('serves exactly the bytes the publish command writes', function () {
    config([
        'app.env' => 'production',
        'app.url' => 'https://ghes.ro',
        'eventpulse.seo.robots.public_hosts' => ['ghes.ro'],
    ]);

    $path = tempnam(sys_get_temp_dir(), 'robots');

    $this->artisan('seo:publish-robots', ['--path' => $path])->assertSuccessful();

    // The route is the fallback for the file nginx serves. If they can drift,
    // the policy a crawler reads depends on which one answered — which is the
    // failure this pairing exists to prevent.
    expect(file_get_contents($path))->toBe($this->get('/robots.txt')->getContent());

    unlink($path);
});
