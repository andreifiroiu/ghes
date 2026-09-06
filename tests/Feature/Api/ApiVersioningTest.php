<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('serves version 1 under /api/v1', function () {
    Event::factory()->create(['starts_at' => now()->addDay()]);
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/events')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('answers the retired unversioned paths with 410 and an upgrade code', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/events')
        ->assertStatus(410)
        ->assertJsonPath('error.code', 'upgrade_required');

    // Any method, any depth — an old build must never see a 404 here.
    $this->postJson('/api/auth/login', [])
        ->assertStatus(410)
        ->assertJsonPath('error.code', 'upgrade_required');
});

it('reports an unknown v1 path as not found, not as a retired version', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/no-such-thing')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});

it('renders a bare /api/v1 through the error envelope', function () {
    $this->getJson('/api/v1')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});

it('does not let the catch-all shadow the health check', function () {
    $this->get('/up')->assertOk();
});

it('applies the api throttle to every v1 route', function () {
    // Every route in the api group carries the named limiter; the guard test
    // proves it for each route by inspection, this proves it actually bites.
    config(['eventpulse.api.throttle.per_minute' => 2]);

    $this->getJson('/api/v1/meta')->assertOk();
    $this->getJson('/api/v1/meta')->assertOk();

    $this->getJson('/api/v1/meta')
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertJsonPath('error.retry_after', fn ($value) => is_int($value) && $value > 0)
        ->assertHeader('Retry-After');
});

it('never collapses to one request per minute on a blank or zero limit', function () {
    config(['eventpulse.api.throttle.per_minute' => 0]);

    $this->getJson('/api/v1/meta')->assertOk();
    $this->getJson('/api/v1/meta')->assertOk();
});

it('keys a rejected bearer token by the token, not the shared IP', function () {
    config(['eventpulse.api.throttle.per_minute' => 1]);

    // Two stale clients behind one NAT: each gets its own bucket, and the
    // second still learns it is unauthenticated rather than rate limited.
    $this->withToken('1|stale-token-one')->getJson('/api/v1/profile')
        ->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    $this->withToken('2|stale-token-two')->getJson('/api/v1/profile')
        ->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('renders the catch-all throttle through the error envelope too', function () {
    config(['eventpulse.api.throttle.per_minute' => 1]);

    $this->getJson('/api/events')->assertStatus(410);
    $this->getJson('/api/events')->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
});

it('keys the throttle by user so one token cannot exhaust another', function () {
    config(['eventpulse.api.throttle.per_minute' => 1]);

    $first = User::factory()->create();
    $second = User::factory()->create();

    $this->withToken($first->createToken('api')->plainTextToken)
        ->getJson('/api/v1/profile')->assertOk();
    $this->withToken($first->createToken('api')->plainTextToken)
        ->getJson('/api/v1/profile')->assertStatus(429);

    // The sanctum guard caches the user it resolved for the rest of the test,
    // so without this the second token would still be read as the first user.
    app('auth')->forgetGuards();

    $this->withToken($second->createToken('api')->plainTextToken)
        ->getJson('/api/v1/profile')->assertOk();
});
