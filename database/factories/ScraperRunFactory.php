<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ScraperRunStatus;
use App\Models\ScraperRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScraperRun>
 */
class ScraperRunFactory extends Factory
{
    protected $model = ScraperRun::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => fake()->randomElement(['eventbrite', 'meetup', 'generic_html', 'rss_feed']),
            'status' => fake()->randomElement(ScraperRunStatus::cases()),
            'events_found' => fake()->numberBetween(0, 50),
            'events_created' => fake()->numberBetween(0, 30),
            'events_updated' => fake()->numberBetween(0, 10),
            'events_skipped' => fake()->numberBetween(0, 10),
            'errors_count' => 0,
            'error_log' => [],
            'started_at' => now()->subMinutes(5),
            'finished_at' => now(),
        ];
    }

    /**
     * Indicate that the scraper run failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ScraperRunStatus::Failed,
            'errors_count' => fake()->numberBetween(1, 10),
            // A list of plain strings, matching what the orchestrator writes.
            'error_log' => ['Connection timeout'],
        ]);
    }
}
