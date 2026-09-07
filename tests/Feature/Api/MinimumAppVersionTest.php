<?php

declare(strict_types=1);

use App\Http\Middleware\EnforceMinimumAppVersion;

it('passes a request that sends no version header', function () {
    config(['eventpulse.mobile.min_supported_version' => '2.0.0']);

    $this->getJson('/api/v1/meta')->assertOk();
});

it('passes a build at or above the floor', function () {
    config(['eventpulse.mobile.min_supported_version' => '2.0.0']);

    $this->withHeader(EnforceMinimumAppVersion::HEADER, '2.0.0')->getJson('/api/v1/meta')->assertOk();
    $this->withHeader(EnforceMinimumAppVersion::HEADER, '2.1.3')->getJson('/api/v1/meta')->assertOk();
});

it('answers a build below the floor with 426 and the upgrade code', function () {
    config(['eventpulse.mobile.min_supported_version' => '2.0.0']);

    $this->withHeader(EnforceMinimumAppVersion::HEADER, '1.9.9')
        ->getJson('/api/v1/meta')
        ->assertStatus(426)
        ->assertJsonPath('error.code', 'upgrade_required')
        ->assertJsonPath('error.details.min_supported_version', '2.0.0');
});

it('compares component-wise so short, prefixed and pre-release versions are not locked out', function () {
    config(['eventpulse.mobile.min_supported_version' => '1.2.0']);

    // Raw version_compare() ranks every one of these below 1.2.0.
    foreach (['1.2', 'v1.2.0', '1.2.0-beta.3', '1.2.0+42', 'V1.3', '1.10'] as $header) {
        $this->withHeader(EnforceMinimumAppVersion::HEADER, $header)
            ->getJson('/api/v1/meta')
            ->assertOk("{$header} was rejected against a 1.2.0 floor");
    }

    foreach (['1.1', 'v1.1.9', '1.1.9-rc.1', '0.9'] as $header) {
        $this->withHeader(EnforceMinimumAppVersion::HEADER, $header)
            ->getJson('/api/v1/meta')
            ->assertStatus(426, "{$header} was accepted against a 1.2.0 floor");
    }
});

it('ignores a header it cannot read as a version', function () {
    config(['eventpulse.mobile.min_supported_version' => '2.0.0']);

    foreach (['garbage', '', '1.2.3.4.5', 'latest'] as $header) {
        $this->withHeader(EnforceMinimumAppVersion::HEADER, $header)->getJson('/api/v1/meta')->assertOk();
    }
});

it('reads a short configured floor the same way', function () {
    config(['eventpulse.mobile.min_supported_version' => '2']);

    $this->withHeader(EnforceMinimumAppVersion::HEADER, '2.0.0')->getJson('/api/v1/meta')->assertOk();
    $this->withHeader(EnforceMinimumAppVersion::HEADER, '1.9.9')
        ->getJson('/api/v1/meta')
        ->assertStatus(426)
        ->assertJsonPath('error.details.min_supported_version', '2.0.0');
});

it('enforces nothing while no floor is configured', function () {
    config(['eventpulse.mobile.min_supported_version' => null]);

    $this->withHeader(EnforceMinimumAppVersion::HEADER, '0.0.1')->getJson('/api/v1/meta')->assertOk();
});

it('does not apply to the web routes', function () {
    config(['eventpulse.mobile.min_supported_version' => '2.0.0']);

    $this->withHeader(EnforceMinimumAppVersion::HEADER, '0.0.1')->get('/events')->assertOk();
});
