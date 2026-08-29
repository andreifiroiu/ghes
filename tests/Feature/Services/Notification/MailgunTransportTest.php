<?php

declare(strict_types=1);

use Illuminate\Support\Env;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Bridge\Mailgun\Transport\MailgunHttpTransport;

/**
 * Guards the wiring rather than the sending: that the Mailgun transport is
 * installed and reachable through config, and that it points at the EU region.
 * A missing package or a dropped config key surfaces here instead of as a digest
 * that silently fails to go out at 08:00.
 */
beforeEach(function () {
    config()->set('services.mailgun', [
        'domain' => 'mg.ghes.ro',
        'secret' => 'key-test',
        'endpoint' => 'api.eu.mailgun.net',
        'scheme' => 'https',
    ]);
});

it('resolves the mailgun mailer', function () {
    expect(Mail::mailer('mailgun')->getSymfonyTransport())
        ->toBeInstanceOf(MailgunHttpTransport::class);
});

/**
 * Ghes's sending domain lives in Mailgun's EU region. Pointed at the US host,
 * every send comes back 401 — which reads like a bad key, not a wrong region.
 */
it('points the transport at the eu region and the configured domain', function () {
    expect((string) Mail::mailer('mailgun')->getSymfonyTransport())
        ->toContain('api.eu.mailgun.net')
        ->toContain('domain=mg.ghes.ro');
});

it('falls back to the eu endpoint when MAILGUN_ENDPOINT is unset', function () {
    $original = Env::get('MAILGUN_ENDPOINT');
    Env::getRepository()->clear('MAILGUN_ENDPOINT');

    try {
        $services = require config_path('services.php');

        expect($services['mailgun']['endpoint'])->toBe('api.eu.mailgun.net');
    } finally {
        if ($original !== null) {
            Env::getRepository()->set('MAILGUN_ENDPOINT', (string) $original);
        }
    }
});
