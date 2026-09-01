<?php

declare(strict_types=1);

namespace App\Services\City;

use App\Services\Processing\EventTextNormalizer;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The cities Ghes actually covers, read from `config('eventpulse.cities')`.
 *
 * User-facing city values are stored as *labels* ("Timișoara"), not config
 * keys — that is what `users.city` has always held and what the dashboard
 * renders directly. Matching against `events.city_slug` goes through
 * {@see EventTextNormalizer::citySlug()} either way.
 */
class CityCatalog
{
    /**
     * Every configured city as key => label, e.g. `['timisoara' => 'Timișoara']`.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        /** @var array<string, array{label?: string}> $cities */
        $cities = (array) config('eventpulse.cities', []);

        $options = [];

        foreach ($cities as $key => $city) {
            $options[(string) $key] = (string) ($city['label'] ?? $key);
        }

        return $options;
    }

    /**
     * The configured city labels, for validation rules and select options.
     *
     * @return list<string>
     */
    public static function labels(): array
    {
        return array_values(self::options());
    }

    /**
     * The default city's display label (e.g. "Timișoara").
     *
     * Guarantees the result is one of {@see labels()}. Reading the label
     * straight off `eventpulse.default_city` did not: a `default_city` naming
     * a city absent from `cities` returned the bare key, and that key then
     * became the column default, every new account's city, and a value
     * `ProfileUpdateRequest` rejects as uncovered — a deployment where users
     * are created holding a city their own profile form calls invalid, with
     * an empty feed and nothing in the logs to say why.
     */
    public static function defaultLabel(): string
    {
        $options = self::options();
        $key = (string) config('eventpulse.default_city');

        if (isset($options[$key])) {
            return $options[$key];
        }

        if ($options === []) {
            throw new RuntimeException(
                'No cities are configured under eventpulse.cities; Ghes cannot resolve a default city.'
            );
        }

        $fallback = reset($options);

        Log::error('Configured default_city is not a known city; falling back.', [
            'default_city' => $key,
            'known_cities' => array_keys($options),
            'using' => $fallback,
        ]);

        return $fallback;
    }

    /**
     * Resolve free text to a canonical configured label, or null when it
     * matches no covered city.
     *
     * Matching is slug-based, so "timisoara", "Timisoara" and "Timișoara" all
     * resolve to "Timișoara". Keys match as readily as labels, so an LLM that
     * answers with the config key still lands on the right city.
     */
    public static function resolveLabel(?string $city): ?string
    {
        $slug = EventTextNormalizer::citySlug($city);

        if ($slug === null) {
            return null;
        }

        foreach (self::options() as $key => $label) {
            if (EventTextNormalizer::citySlug($label) === $slug
                || EventTextNormalizer::citySlug($key) === $slug) {
                return $label;
            }
        }

        return null;
    }
}
