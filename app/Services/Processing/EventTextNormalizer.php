<?php

declare(strict_types=1);

namespace App\Services\Processing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * The single text-normalisation authority for event identity.
 *
 * Every fingerprint, match key and fuzzy comparison in the pipeline goes
 * through this class, so that a title scraped as "Concert Phoenix @ Sala
 * Capitol" and one scraped as "Sala Capitol: Concert Phoenix - Live in
 * Timișoara" reduce to the same key.
 *
 * All methods are static and side-effect free so both AbstractHtmlScraper
 * and adapters that implement ScraperAdapter directly can delegate to it
 * without touching their constructors.
 */
final class EventTextNormalizer
{
    /**
     * Romanian diacritics in both the official comma-below encoding and the
     * legacy cedilla encoding that several scraped sites still emit.
     *
     * @var array<string, string>
     */
    private const DIACRITICS = [
        // Comma-below forms (official Unicode)
        'ș' => 's', 'Ș' => 's',
        'ț' => 't', 'Ț' => 't',
        // Cedilla forms (common legacy encoding)
        'ş' => 's', 'Ş' => 's',
        'ţ' => 't', 'Ţ' => 't',
        // Other Romanian vowels
        'ă' => 'a', 'Ă' => 'a',
        'â' => 'a', 'Â' => 'a',
        'î' => 'i', 'Î' => 'i',
    ];

    /**
     * Sentinel used when an event has no known start date, so that the
     * occurrence key can stay NOT NULL in the database.
     */
    public const UNDATED = 'undated';

    /**
     * Replace Romanian diacritics with their ASCII equivalents.
     */
    public static function foldDiacritics(string $text): string
    {
        return strtr($text, self::DIACRITICS);
    }

    /**
     * Lowercase, fold diacritics, collapse whitespace, and trim.
     *
     * Punctuation is deliberately preserved: this is the general-purpose
     * normaliser used for city matching by the scraper adapters.
     */
    public static function normalizeText(string $text): string
    {
        $folded = mb_strtolower(self::foldDiacritics($text));

        return trim((string) preg_replace('/\s+/u', ' ', $folded));
    }

    /**
     * Normalise a title for comparison: no diacritics, no punctuation,
     * single-spaced, lowercase.
     */
    public static function normalizeTitle(string $title): string
    {
        $stripped = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', self::foldDiacritics($title));

        return self::normalizeText((string) $stripped);
    }

    /**
     * Reduce a title to its significant, order-independent tokens.
     *
     * Noise words, configured city labels, pure-numeric year tokens and
     * tokens shorter than two characters are dropped; the remainder is
     * de-duplicated and sorted so that word order cannot change the key.
     *
     * @return list<string>
     */
    public static function titleTokens(string $title): array
    {
        // Strip a trailing venue ("Concert Phoenix @ Casa Tineretului") first:
        // one provider embeds the venue in the heading while another does not,
        // and the two must still reduce to the same key.
        [$titleOnly] = self::splitTitleVenue($title);

        $tokens = preg_split('/\s+/u', self::normalizeTitle($titleOnly), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $noise = self::noiseWords();

        $significant = array_filter($tokens, static function (string $token) use ($noise): bool {
            if (mb_strlen($token) < 2) {
                return false;
            }

            if (in_array($token, $noise, strict: true)) {
                return false;
            }

            // Bare years ("2026") carry no identity and drift between sources.
            return preg_match('/^(19|20)\d{2}$/', $token) !== 1;
        });

        $unique = array_values(array_unique($significant));
        sort($unique);

        return $unique;
    }

    /**
     * A stable, human-readable identity key for a title.
     */
    public static function titleKey(string $title): string
    {
        $tokens = self::titleTokens($title);

        if ($tokens === []) {
            // Fall back to the normalised title so a title made entirely of
            // noise words still produces something comparable.
            return str_replace(' ', '-', self::normalizeTitle($title));
        }

        return implode('-', $tokens);
    }

    /**
     * Split a scraped heading into its title and (optional) venue halves.
     *
     * Sources publish "Title @ Venue", "Title // Venue", "Title | Venue" and
     * "City: Title". Only the first two are treated as venue separators; a
     * leading "City:" prefix is stripped without producing a venue.
     *
     * @return array{0: string, 1: ?string}
     */
    public static function splitTitleVenue(string $raw): array
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $raw));

        // Strip a leading "City: " prefix when it names a configured city.
        foreach (self::cityLabels() as $label) {
            $prefix = $label.':';
            if (Str::startsWith(self::normalizeText($value), $prefix.' ')) {
                $value = trim(mb_substr($value, mb_strlen($prefix)));
                break;
            }
        }

        /** @var list<string> $separators */
        $separators = config('eventpulse.dedup.title_separators', [' @ ', ' // ', ' | ']);

        foreach ($separators as $separator) {
            $position = mb_strrpos($value, $separator);

            if ($position === false || $position === 0) {
                continue;
            }

            $title = trim(mb_substr($value, 0, $position));
            $venue = trim(mb_substr($value, $position + mb_strlen($separator)));

            if ($title !== '' && $venue !== '') {
                return [$title, $venue];
            }
        }

        return [$value, null];
    }

    /**
     * Collapse a city name to a slug, so "Timișoara", "Timisoara" and
     * "timisoara" all compare equal.
     */
    public static function citySlug(?string $city): ?string
    {
        if ($city === null || trim($city) === '') {
            return null;
        }

        $slug = Str::slug(self::foldDiacritics($city));

        return $slug === '' ? null : $slug;
    }

    /**
     * Canonicalise a URL for same-source identity: lowercase host, no "www."
     * or "m." prefix, no query string, no fragment, no trailing slash.
     */
    public static function normalizeUrl(string $url): string
    {
        $trimmed = trim($url);
        $parts = parse_url($trimmed);

        if ($parts === false || ! isset($parts['host'])) {
            // Not a parseable absolute URL — fall back to a lexical cleanup.
            return rtrim(mb_strtolower((string) strtok($trimmed, '?#')), '/');
        }

        $host = mb_strtolower($parts['host']);
        $host = (string) preg_replace('/^(www|m)\./', '', $host);

        $scheme = isset($parts['scheme']) ? mb_strtolower($parts['scheme']) : 'https';
        $path = rtrim($parts['path'] ?? '', '/');

        return $scheme.'://'.$host.$path;
    }

    /**
     * The local calendar date of a UTC timestamp, in the city's timezone.
     *
     * This is what makes matching robust: sources disagree wildly on the
     * time of day (and some store a bare date), but they agree on the day.
     */
    public static function localDate(?string $utcDateTime, string $timezone): ?string
    {
        if ($utcDateTime === null || trim($utcDateTime) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($utcDateTime)->setTimezone($timezone)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The occurrence key used in the event_sources unique constraint.
     */
    public static function occurrenceKey(?string $localDate): string
    {
        return $localDate ?? self::UNDATED;
    }

    /**
     * The blocking key used to find candidate duplicates.
     *
     * Deliberately readable rather than hashed: it shows up in dry-run
     * output and logs during rollout, where "timisoara|2026-04-18|concert-
     * phoenix-sala-capitol" is worth far more than a sha256.
     */
    public static function matchKey(string $title, ?string $city, ?string $localDate): string
    {
        $key = implode('|', [
            self::citySlug($city) ?? '-',
            $localDate ?? self::UNDATED,
            self::titleKey($title),
        ]);

        return Str::limit($key, 191, '');
    }

    /**
     * Noise words stripped from titles: the configured list plus the labels
     * of every configured city, so "Live in Timisoara" suffixes disappear
     * without hardcoding city names here.
     *
     * @return list<string>
     */
    private static function noiseWords(): array
    {
        /** @var list<string> $configured */
        $configured = config('eventpulse.dedup.title_noise_words', []);

        $words = array_map(self::normalizeText(...), $configured);

        foreach (self::cityLabels() as $label) {
            foreach (preg_split('/\s+/u', $label, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                $words[] = $part;
            }
        }

        return array_values(array_unique($words));
    }

    /**
     * Normalised labels and keys of every configured city.
     *
     * @return list<string>
     */
    private static function cityLabels(): array
    {
        /** @var array<string, array{label?: string}> $cities */
        $cities = config('eventpulse.cities', []);

        $labels = [];

        foreach ($cities as $key => $city) {
            $labels[] = self::normalizeText((string) $key);

            if (isset($city['label'])) {
                $labels[] = self::normalizeText($city['label']);
            }
        }

        return array_values(array_unique($labels));
    }
}
