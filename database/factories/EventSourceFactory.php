<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventSource;
use App\Services\Processing\EventTextNormalizer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventSource>
 */
class EventSourceFactory extends Factory
{
    protected $model = EventSource::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $url = fake()->unique()->url();
        $startsAt = fake()->dateTimeBetween('now', '+30 days');

        return [
            'event_id' => Event::factory(),
            'source' => fake()->randomElement(['iabilet', 'zilesinopti', 'allevents', 'eventbrite']),
            'source_url' => $url,
            'url_key' => EventTextNormalizer::normalizeUrl($url),
            'source_id' => fake()->uuid(),
            'occurrence_key' => $startsAt->format('Y-m-d'),
            'title' => fake()->sentence(3),
            'starts_at' => $startsAt,
            'payload' => [],
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }

    /**
     * State for a source belonging to a specific adapter key.
     */
    public function forSource(string $source): self
    {
        return $this->state(fn (): array => ['source' => $source]);
    }
}
