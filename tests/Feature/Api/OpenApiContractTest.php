<?php

declare(strict_types=1);

use App\Enums\ApiErrorCode;
use App\Enums\EventCategory;
use App\Models\Event;
use App\Models\User;
use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Yaml\Yaml;

/**
 * Keeps openapi/v1.yaml honest. The spec is hand-maintained, so the only
 * thing stopping it drifting from the routes is this file: every registered
 * api/v1 operation must be in the spec, and every spec operation must be a
 * real route. Admin routes are web-only by decision and are excluded.
 */

/**
 * @return array<string, mixed>
 */
function openApiSpec(): array
{
    /** @var array<string, mixed> $spec */
    $spec = Yaml::parseFile(base_path('openapi/v1.yaml'));

    return $spec;
}

/**
 * Every (method, path) the application serves under api/v1, as the spec
 * would write it: relative to the `/api/v1` server, HEAD dropped.
 *
 * @return list<string>
 */
function servedOperations(): array
{
    $operations = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        /** @var RouteInstance $route */
        $uri = $route->uri();

        if (! str_starts_with($uri, 'api/v1/') || str_starts_with($uri, 'api/v1/admin/')) {
            continue;
        }

        $path = '/'.substr($uri, strlen('api/v1/'));

        foreach ($route->methods() as $method) {
            if ($method === 'HEAD') {
                continue;
            }

            $operations[] = strtolower($method).' '.$path;
        }
    }

    sort($operations);

    return $operations;
}

/**
 * @return list<string>
 */
function specifiedOperations(): array
{
    $operations = [];

    /** @var array<string, array<string, mixed>> $paths */
    $paths = openApiSpec()['paths'];

    foreach ($paths as $path => $item) {
        foreach (array_keys($item) as $method) {
            if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                $operations[] = $method.' '.$path;
            }
        }
    }

    sort($operations);

    return $operations;
}

it('parses and declares the v1 server', function () {
    $spec = openApiSpec();

    expect($spec['openapi'])->toStartWith('3.1')
        ->and($spec['servers'][0]['url'])->toBe('/api/v1');
});

it('documents every served operation and serves every documented one', function () {
    $served = servedOperations();
    $specified = specifiedOperations();

    expect(array_values(array_diff($served, $specified)))
        ->toBe([], 'Routes registered under api/v1 but missing from openapi/v1.yaml');

    expect(array_values(array_diff($specified, $served)))
        ->toBe([], 'Operations in openapi/v1.yaml that no route serves');
});

/**
 * Compare a live payload against a schema's property list: every key the
 * server sent must be declared, and every declared required key must be
 * sent. Optional properties (conditional relations) may be absent.
 *
 * @param  array<string, mixed>  $payload
 * @param  array<string, mixed>  $schema
 */
function expectKeysToMatchSchema(array $payload, array $schema): void
{
    $declared = array_keys($schema['properties']);
    $required = $schema['required'] ?? [];
    $sent = array_keys($payload);

    expect(array_values(array_diff($sent, $declared)))
        ->toBe([], 'Keys the server sent that the spec does not declare');

    expect(array_values(array_diff($required, $sent)))
        ->toBe([], 'Keys the spec requires that the server did not send');
}

it('serves events shaped like the Event schema', function () {
    $user = User::factory()->create();
    Event::factory()->create(['starts_at' => now()->addDay()]);
    Sanctum::actingAs($user, ['*']);

    $event = $this->getJson('/api/v1/events')->assertOk()->json('data.0');

    expectKeysToMatchSchema($event, openApiSpec()['components']['schemas']['Event']);
});

it('serves the profile shaped like the User schema', function () {
    Sanctum::actingAs(User::factory()->create(), ['*']);

    $user = $this->getJson('/api/v1/profile')->assertOk()->json('data');

    expectKeysToMatchSchema($user, openApiSpec()['components']['schemas']['User']);
});

it('serves meta shaped like the Meta schema', function () {
    $meta = $this->getJson('/api/v1/meta')->assertOk()->json('data');

    expectKeysToMatchSchema($meta, openApiSpec()['components']['schemas']['Meta']);
});

it('keeps the error code enum in step with ApiErrorCode', function () {
    $documented = openApiSpec()['components']['schemas']['ErrorCode']['enum'];
    $actual = array_column(ApiErrorCode::cases(), 'value');

    sort($documented);
    sort($actual);

    expect($documented)->toBe($actual);
});

it('keeps the category enum in step with EventCategory', function () {
    $documented = openApiSpec()['components']['schemas']['Category']['enum'];
    $actual = array_column(EventCategory::cases(), 'value');

    expect($documented)->toBe($actual);
});
