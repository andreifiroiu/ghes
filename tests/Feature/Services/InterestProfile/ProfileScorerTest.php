<?php

declare(strict_types=1);

use App\Services\InterestProfile\ProfileScorer;

beforeEach(function () {
    $this->scorer = new ProfileScorer;
});

it('returns the category score from the profile', function () {
    $profile = ['Music' => 0.8, 'Sports' => 0.3];

    expect($this->scorer->calculateCategoryScore($profile, 'Music'))->toBe(0.8);
    expect($this->scorer->calculateCategoryScore($profile, 'Sports'))->toBe(0.3);
});

it('returns zero for unknown categories', function () {
    $profile = ['Music' => 0.8];

    expect($this->scorer->calculateCategoryScore($profile, 'Technology'))->toBe(0.0);
});

it('calculates average tag score from matching tags', function () {
    $profile = [
        'tag:jazz' => 0.9,
        'tag:live-music' => 0.7,
        'tag:outdoor' => 0.5,
    ];

    // Average of 0.9 and 0.7
    $score = $this->scorer->calculateTagScore($profile, ['jazz', 'live-music']);
    expect($score)->toEqualWithDelta(0.8, 0.0001);
});

it('returns zero for empty tags', function () {
    $profile = ['tag:jazz' => 0.9];

    expect($this->scorer->calculateTagScore($profile, []))->toBe(0.0);
});

it('returns zero for tags not in profile', function () {
    $profile = ['tag:jazz' => 0.9];

    $score = $this->scorer->calculateTagScore($profile, ['unknown-tag']);
    expect($score)->toBe(0.0);
});

it('handles mixed known and unknown tags', function () {
    $profile = [
        'tag:jazz' => 0.8,
    ];

    // Average of 0.8 (jazz) and 0.0 (rock) = 0.4
    $score = $this->scorer->calculateTagScore($profile, ['jazz', 'rock']);
    expect($score)->toBe(0.4);
});

it('calculates average source score across the reporting providers', function () {
    $profile = [
        'source:iabilet' => 0.9,
        'source:allevents' => 0.5,
        'source:meetup' => 0.1,
    ];

    $score = $this->scorer->calculateSourceScore($profile, ['iabilet', 'allevents']);
    expect($score)->toEqualWithDelta(0.7, 0.0001);
});

it('returns zero source score for events with no known sources', function () {
    $profile = ['source:iabilet' => 0.9];

    expect($this->scorer->calculateSourceScore($profile, []))->toBe(0.0);
    expect($this->scorer->calculateSourceScore($profile, ['unknown']))->toBe(0.0);
});
