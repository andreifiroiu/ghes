<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\InterestProfile\ProfilePresenter;

// A Feature test, like its ProfileScorer/ProfileUpdater/ProfileDecayer siblings:
// the presenter logs an unrecognised key, and tests/Unit boots no application,
// so the Log facade has no root there.

function presentProfile(array $profile): array
{
    return (new ProfilePresenter)->present(new User(['interest_profile' => $profile]));
}

it('splits the flat interest profile into categories, tags and sources', function () {
    // The regression this guards: the profile page used to read
    // `interest_profile.categories`, a sub-key nothing has ever written, so a
    // fully onboarded account saw "Niciun interes înregistrat încă".
    $result = presentProfile([
        'music' => 0.9,
        'arts' => 0.4,
        'tag:jazz' => 0.8,
        'source:iabilet' => 0.7,
    ]);

    expect($result['categories'])->toBe([
        ['key' => 'music', 'score' => 0.9],
        ['key' => 'arts', 'score' => 0.4],
    ]);
    expect($result['tags'])->toBe([['key' => 'jazz', 'score' => 0.8]]);
    expect($result['sources'])->toBe([['key' => 'iabilet', 'score' => 0.7]]);
});

it('sorts every family by score, strongest first', function () {
    $result = presentProfile([
        'arts' => 0.2,
        'music' => 0.9,
        'film' => 0.5,
        'tag:rock' => 0.4,
        'tag:jazz' => 0.95,
        'source:allevents' => 0.1,
        'source:iabilet' => 0.6,
    ]);

    expect(array_column($result['categories'], 'key'))->toBe(['music', 'film', 'arts']);
    expect(array_column($result['tags'], 'key'))->toBe(['jazz', 'rock']);
    expect(array_column($result['sources'], 'key'))->toBe(['iabilet', 'allevents']);
});

it('drops tags below the display floor', function () {
    // A single reaction seeds a tag at 0.20 and decay never fully clears it,
    // so showing every tag would bury the ones the user actually cares about.
    $result = presentProfile(['tag:jazz' => 0.3, 'tag:noise' => 0.29]);

    expect(array_column($result['tags'], 'key'))->toBe(['jazz']);
});

it('ignores non-numeric and unknown keys', function () {
    // Older profile generations wrote `city` and `price_sensitive` into this
    // same blob, and ProfileDecayer still steps over them rather than dropping.
    $result = presentProfile([
        'city' => 'Timișoara',
        'price_sensitive' => true,
        'preferred_times' => ['evening'],
        'not_a_category' => 0.8,
        'music' => 0.5,
    ]);

    expect($result['categories'])->toBe([['key' => 'music', 'score' => 0.5]]);
    expect($result['tags'])->toBe([]);
    expect($result['sources'])->toBe([]);
});

it('returns three empty families for an untouched profile', function () {
    expect(presentProfile([]))->toBe([
        'categories' => [],
        'tags' => [],
        'sources' => [],
    ]);
});
