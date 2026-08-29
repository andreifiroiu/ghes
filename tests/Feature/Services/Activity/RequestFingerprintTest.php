<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Activity\RequestFingerprint;
use Illuminate\Http\Request;

/**
 * @param  array<string, string>  $server
 */
function fingerprintFor(array $server): RequestFingerprint
{
    return new RequestFingerprint(Request::create('/go/x', 'GET', [], [], [], $server));
}

it('treats a browser user agent as human', function () {
    $fingerprint = fingerprintFor(['HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh) Chrome/120.0 Safari/537.36']);

    expect($fingerprint->isBot())->toBeFalse();
});

it('flags a missing user agent on anonymous traffic as automated', function () {
    // Every real browser sends one; an anonymous request without it is a script.
    $fingerprint = fingerprintFor(['HTTP_USER_AGENT' => '']);

    expect($fingerprint->isBot())->toBeTrue()
        ->and($fingerprint->botReason())->toBe('missing_ua');
});

it('does not flag an authenticated client that sends no user agent', function () {
    $request = Request::create('/api/events', 'GET', [], [], [], ['HTTP_USER_AGENT' => '']);
    $request->setUserResolver(fn () => User::factory()->create());

    // Native and server-side API clients routinely send no User-Agent. Flagging
    // them would mean their clicks never reach their profile — permanently,
    // because the same client sends the same (absent) header every time.
    expect((new RequestFingerprint($request))->isBot())->toBeFalse();
});

it('does not flag rows written outside an HTTP request', function () {
    // Console and queue context: our own code wrote the row, there is no
    // browser. Digest impressions are logged from a queued job, and flagging
    // them would drop them out of the click-through denominator.
    expect((new RequestFingerprint(Request::create('/')))->isBot())->toBeFalse();
});

it('flags known scanners and prefetchers regardless of case', function (string $userAgent) {
    $fingerprint = fingerprintFor(['HTTP_USER_AGENT' => $userAgent]);

    expect($fingerprint->isBot())->toBeTrue()
        ->and($fingerprint->botReason())->toBe('ua_denylist');
})->with([
    'Proofpoint' => 'Mozilla/5.0 (compatible; ProofPoint URL Defense)',
    'Slack unfurl' => 'Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)',
    'curl' => 'curl/8.4.0',
    'WhatsApp' => 'WhatsApp/2.23',
]);

it('records no session key when the request carries no session', function () {
    expect(fingerprintFor(['HTTP_USER_AGENT' => 'Chrome'])->sessionKey())->toBeNull();
});

it('hashes the session id rather than storing it', function () {
    $request = Request::create('/go/x');
    $request->setLaravelSession(app('session.store'));
    $sessionId = $request->session()->getId();

    $key = (new RequestFingerprint($request))->sessionKey();

    // Stable enough to group one visitor's page views, and not reversible into
    // anything that identifies them.
    expect($key)->toBeString()
        ->and($key)->toHaveLength(64)
        ->and($key)->not->toContain($sessionId)
        ->and($key)->toBe((new RequestFingerprint($request))->sessionKey());
});

it('does not flag a mail image proxy', function (string $userAgent) {
    // An image proxy fetches <img> and nothing else, so it can never inflate a
    // click — and it fetches when the reader opens the message, which makes
    // that request the open signal rather than noise to filter out.
    expect(fingerprintFor(['HTTP_USER_AGENT' => $userAgent])->isBot())->toBeFalse();
})->with([
    'GoogleImageProxy' => 'Mozilla/5.0 (via ggpht.com GoogleImageProxy)',
    'YahooMailProxy' => 'YahooMailProxy; https://help.yahoo.com/kb/yahoo-mail-proxy',
]);
