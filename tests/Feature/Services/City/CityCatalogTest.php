<?php

declare(strict_types=1);

use App\Services\City\CityCatalog;

it('lists the configured cities as key => label', function () {
    expect(CityCatalog::options())->toHaveKey('timisoara')
        ->and(CityCatalog::options()['timisoara'])->toBe('Timișoara')
        ->and(CityCatalog::labels())->toContain('Timișoara');
});

it('reports the default city label', function () {
    expect(CityCatalog::defaultLabel())->toBe('Timișoara');
});

it('always returns a default that is one of the offered labels', function () {
    // The invariant the migration, the model hook and ProfileUpdateRequest all
    // rely on. Without it a deployment creates accounts holding a city its own
    // profile form rejects.
    expect(CityCatalog::labels())->toContain(CityCatalog::defaultLabel());
});

it('falls back to a real city when default_city names an unconfigured one', function () {
    config(['eventpulse.default_city' => 'cluj']);

    expect(CityCatalog::defaultLabel())->toBe('Timișoara')
        ->and(CityCatalog::labels())->toContain(CityCatalog::defaultLabel());
});

it('refuses to guess when no city is configured at all', function () {
    config(['eventpulse.cities' => []]);

    expect(fn () => CityCatalog::defaultLabel())->toThrow(RuntimeException::class);
});

it('falls back to the config key when a city has no label', function () {
    config(['eventpulse.cities' => ['arad' => ['timezone' => 'Europe/Bucharest']]]);
    config(['eventpulse.default_city' => 'arad']);

    expect(CityCatalog::options())->toBe(['arad' => 'arad'])
        ->and(CityCatalog::defaultLabel())->toBe('arad');
});

it('canonicalises any spelling of a covered city to its label', function (string $input) {
    expect(CityCatalog::resolveLabel($input))->toBe('Timișoara');
})->with(['Timișoara', 'Timisoara', 'timisoara', 'TIMISOARA', '  timișoara  ']);

it('rejects a city no source covers', function () {
    expect(CityCatalog::resolveLabel('București'))->toBeNull()
        ->and(CityCatalog::resolveLabel('Cluj-Napoca'))->toBeNull();
});

it('rejects blank input', function (?string $input) {
    expect(CityCatalog::resolveLabel($input))->toBeNull();
})->with([null, '', '   ', '!!!']);
