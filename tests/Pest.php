<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Headers that make a test request look like a real browser.
 *
 * RequestFingerprint treats a missing User-Agent as automated, because every
 * real browser sends one and mail scanners frequently do not. Laravel's test
 * client sends none, so any test that cares whether a hit counts as human
 * traffic has to say so explicitly.
 *
 * @return array<string, string>
 */
function browserHeaders(): array
{
    return [
        'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) '.
            'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ];
}
