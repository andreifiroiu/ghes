<?php

declare(strict_types=1);

use App\Services\Seo\RobotsTxt;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->path = tempnam(sys_get_temp_dir(), 'robots');
    unlink($this->path);
});

afterEach(function () {
    if (file_exists($this->path)) {
        unlink($this->path);
    }
});

it('writes the policy to the given path', function () {
    $this->artisan('seo:publish-robots', ['--path' => $this->path])->assertSuccessful();

    expect(file_get_contents($this->path))->toBe(RobotsTxt::fromConfig()->file());
});

it('ends the file with a newline', function () {
    $this->artisan('seo:publish-robots', ['--path' => $this->path]);

    expect(file_get_contents($this->path))->toEndWith("\n");
});

it('names the host it opened the crawl for', function () {
    config([
        'app.env' => 'production',
        'app.url' => 'https://ghes.ro',
        'eventpulse.seo.robots.public_hosts' => ['ghes.ro'],
    ]);

    $this->artisan('seo:publish-robots', ['--path' => $this->path])
        ->expectsOutputToContain('public policy for ghes.ro')
        ->assertSuccessful();
});

it('logs an error when a production host publishes a closed policy', function () {
    // The case this exists for: the deploy succeeds, the log says "Wrote", and
    // the site is de-indexed because APP_URL is misspelt.
    config([
        'app.env' => 'production',
        'app.url' => 'https://ghes.ro.typo',
        'eventpulse.seo.robots.public_hosts' => ['ghes.ro'],
    ]);

    Log::shouldReceive('error')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'closed policy on a production host'));

    $this->artisan('seo:publish-robots', ['--path' => $this->path])
        ->expectsOutputToContain('is not a public host')
        ->assertSuccessful();
});

it('says nothing alarming about a closed policy off production', function () {
    config(['app.env' => 'local', 'app.url' => 'http://ghes.test']);

    Log::shouldReceive('error')->never();

    $this->artisan('seo:publish-robots', ['--path' => $this->path])->assertSuccessful();
});

it('fails the check when the file was never written', function () {
    $this->artisan('seo:publish-robots', ['--path' => $this->path, '--check' => true])
        ->expectsOutputToContain('is missing')
        ->assertFailed();
});

it('fails the check when the policy has drifted from the file', function () {
    // Public host on purpose: off production every policy collapses to
    // `Disallow: /`, so changing the disallow list would change nothing and
    // this guard would pass whether the drift check worked or not.
    config([
        'app.env' => 'production',
        'app.url' => 'https://ghes.ro',
        'eventpulse.seo.robots.public_hosts' => ['ghes.ro'],
    ]);

    $this->artisan('seo:publish-robots', ['--path' => $this->path])->assertSuccessful();

    // A policy change deployed without re-running the hook.
    config(['eventpulse.seo.robots.disallow' => ['/somewhere-new/']]);

    $this->artisan('seo:publish-robots', ['--path' => $this->path, '--check' => true])
        ->expectsOutputToContain('differs from what')
        ->assertFailed();
});

it('passes the check straight after a write', function () {
    $this->artisan('seo:publish-robots', ['--path' => $this->path])->assertSuccessful();

    $this->artisan('seo:publish-robots', ['--path' => $this->path, '--check' => true])->assertSuccessful();
});

it('does not create the file in check mode', function () {
    $this->artisan('seo:publish-robots', ['--path' => $this->path, '--check' => true])->assertFailed();

    expect(file_exists($this->path))->toBeFalse();
});
