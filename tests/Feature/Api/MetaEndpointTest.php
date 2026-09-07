<?php

declare(strict_types=1);

use App\Enums\EventCategory;
use App\Enums\NotificationChannel;
use App\Enums\NotificationFrequency;
use App\Enums\Reaction;

it('is reachable without a token', function () {
    $this->getJson('/api/v1/meta')->assertOk()->assertJsonStructure(['data']);
});

it('publishes the enum vocabularies by backing value', function () {
    $response = $this->getJson('/api/v1/meta')->assertOk();

    expect($response->json('data.categories'))->toBe(array_column(EventCategory::cases(), 'value'))
        ->and($response->json('data.reactions'))->toBe(array_column(Reaction::cases(), 'value'))
        ->and($response->json('data.notification_channels'))->toBe(array_column(NotificationChannel::cases(), 'value'))
        ->and($response->json('data.notification_frequencies'))->toBe(array_column(NotificationFrequency::cases(), 'value'))
        ->and($response->json('data.ranges'))->toBe(['weekend']);
});

it('reports the configured page size rather than a hardcoded one', function () {
    config(['eventpulse.pagination.events' => 7]);

    $this->getJson('/api/v1/meta')->assertJsonPath('data.page_size', 7);
});

it('lists the covered cities with the label the profile endpoints accept', function () {
    $response = $this->getJson('/api/v1/meta')->assertOk();

    expect($response->json('data.cities.0'))->toMatchArray(['key' => 'timisoara', 'label' => 'Timișoara', 'timezone' => 'Europe/Bucharest'])
        ->and($response->json('data.default_city'))->toBe('Timișoara');
});

it('carries the minimum supported app version', function () {
    config(['eventpulse.mobile.min_supported_version' => '1.2.0']);

    $this->getJson('/api/v1/meta')->assertJsonPath('data.min_supported_app_version', '1.2.0');
});
