<?php

declare(strict_types=1);

use App\Services\Processing\EventTextNormalizer;

// ---------------------------------------------------------------------------
// Diacritics
// ---------------------------------------------------------------------------

it('folds Romanian diacritics in both the comma-below and cedilla encodings', function () {
    // Sites emit both: ș U+0219 (official) and ş U+015F (legacy cedilla).
    expect(EventTextNormalizer::foldDiacritics('Timișoara'))->toBe('Timisoara')
        ->and(EventTextNormalizer::foldDiacritics('Timişoara'))->toBe('Timisoara')
        ->and(EventTextNormalizer::foldDiacritics('Înălțare'))->toBe('inaltare');
});

it('normalises text to lowercase, single-spaced and trimmed', function () {
    expect(EventTextNormalizer::normalizeText("  Concert   Phoenix \n"))->toBe('concert phoenix');
});

it('keeps punctuation in normalizeText but drops it in normalizeTitle', function () {
    expect(EventTextNormalizer::normalizeText('Rock & Roll'))->toBe('rock & roll')
        ->and(EventTextNormalizer::normalizeTitle('Rock & Roll'))->toBe('rock roll');
});

// ---------------------------------------------------------------------------
// Title tokens and keys
// ---------------------------------------------------------------------------

it('produces the same token key regardless of word order', function () {
    expect(EventTextNormalizer::titleKey('Phoenix la Capitol'))
        ->toBe(EventTextNormalizer::titleKey('Capitol Phoenix'));
});

it('strips a trailing venue so a heading with one matches a heading without', function () {
    // zilesinopti publishes "Title @ Venue"; iabilet publishes just "Title".
    expect(EventTextNormalizer::titleKey('Concert Phoenix @ Casa Tineretului'))
        ->toBe(EventTextNormalizer::titleKey('Concert Phoenix'));
});

it('strips noise words and city names from a title', function () {
    expect(EventTextNormalizer::titleKey('Concert Phoenix - Live in Timisoara'))
        ->toBe(EventTextNormalizer::titleKey('Concert Phoenix'));
});

it('drops bare years, which drift between sources', function () {
    expect(EventTextNormalizer::titleKey('Revelion 2026'))
        ->toBe(EventTextNormalizer::titleKey('Revelion'));
});

it('keeps distinct acts distinct', function () {
    expect(EventTextNormalizer::titleKey('Concert Phoenix'))
        ->not->toBe(EventTextNormalizer::titleKey('Concert Subcarpati'));
});

it('still produces a key for a title made entirely of noise words', function () {
    expect(EventTextNormalizer::titleKey('Eveniment'))->not->toBeEmpty();
});

// ---------------------------------------------------------------------------
// Title / venue splitting
// ---------------------------------------------------------------------------

it('splits a heading on the venue separators sources use', function () {
    expect(EventTextNormalizer::splitTitleVenue('Concert Phoenix @ Casa Tineretului'))
        ->toBe(['Concert Phoenix', 'Casa Tineretului'])
        ->and(EventTextNormalizer::splitTitleVenue('Concert Phoenix // Capitol'))
        ->toBe(['Concert Phoenix', 'Capitol']);
});

it('strips a leading city prefix without inventing a venue', function () {
    expect(EventTextNormalizer::splitTitleVenue('Timisoara: Concert Phoenix'))
        ->toBe(['Concert Phoenix', null]);
});

it('leaves a heading with no separator untouched', function () {
    expect(EventTextNormalizer::splitTitleVenue('Concert Phoenix'))->toBe(['Concert Phoenix', null]);
});

// ---------------------------------------------------------------------------
// City slugs
// ---------------------------------------------------------------------------

it('collapses city spellings to one slug', function () {
    expect(EventTextNormalizer::citySlug('Timișoara'))->toBe('timisoara')
        ->and(EventTextNormalizer::citySlug('timisoara'))->toBe('timisoara')
        ->and(EventTextNormalizer::citySlug('  Timişoara '))->toBe('timisoara');
});

it('returns null for a missing or blank city', function () {
    expect(EventTextNormalizer::citySlug(null))->toBeNull()
        ->and(EventTextNormalizer::citySlug('   '))->toBeNull();
});

// ---------------------------------------------------------------------------
// URL canonicalisation
// ---------------------------------------------------------------------------

it('canonicalises URLs that differ only cosmetically', function () {
    $canonical = 'https://iabilet.ro/bilete/concert-phoenix';

    expect(EventTextNormalizer::normalizeUrl('https://m.iabilet.ro/bilete/concert-phoenix/'))->toBe($canonical)
        ->and(EventTextNormalizer::normalizeUrl('https://www.iabilet.ro/bilete/concert-phoenix'))->toBe($canonical)
        ->and(EventTextNormalizer::normalizeUrl('https://IaBilet.ro/bilete/concert-phoenix?utm_source=x'))->toBe($canonical)
        ->and(EventTextNormalizer::normalizeUrl('https://iabilet.ro/bilete/concert-phoenix#tickets'))->toBe($canonical);
});

it('keeps genuinely different URLs apart', function () {
    expect(EventTextNormalizer::normalizeUrl('https://iabilet.ro/a'))
        ->not->toBe(EventTextNormalizer::normalizeUrl('https://iabilet.ro/b'));
});

// ---------------------------------------------------------------------------
// Local dates
// ---------------------------------------------------------------------------

it('resolves a UTC timestamp to its local calendar date', function () {
    // 21:00 UTC on the 9th is 00:00 local on the 10th during EEST.
    expect(EventTextNormalizer::localDate('2026-05-09 21:00:00', 'Europe/Bucharest'))->toBe('2026-05-10')
        ->and(EventTextNormalizer::localDate('2026-05-10 17:00:00', 'Europe/Bucharest'))->toBe('2026-05-10');
});

it('returns null for a missing or unparseable timestamp', function () {
    expect(EventTextNormalizer::localDate(null, 'Europe/Bucharest'))->toBeNull()
        ->and(EventTextNormalizer::localDate('not a date', 'Europe/Bucharest'))->toBeNull();
});

it('falls back to a sentinel occurrence key when undated', function () {
    expect(EventTextNormalizer::occurrenceKey(null))->toBe('undated')
        ->and(EventTextNormalizer::occurrenceKey('2026-05-10'))->toBe('2026-05-10');
});

// ---------------------------------------------------------------------------
// Match keys
// ---------------------------------------------------------------------------

it('builds a readable match key', function () {
    expect(EventTextNormalizer::matchKey('Concert Phoenix', 'Timișoara', '2026-05-10'))
        ->toStartWith('timisoara|2026-05-10|');
});

it('caps the match key at the indexable length', function () {
    $key = EventTextNormalizer::matchKey(str_repeat('Phoenix ', 100), 'Timișoara', '2026-05-10');

    expect(mb_strlen($key))->toBeLessThanOrEqual(191);
});
