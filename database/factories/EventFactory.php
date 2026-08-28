<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EventCategory;
use App\Models\Event;
use App\Services\Processing\EventTextNormalizer;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $titlePatterns = [
            'Live Jazz at '.fake()->company(),
            fake()->city().' Tech Meetup',
            'Street Food Festival',
            'Open Mic Night at '.fake()->company(),
            'Yoga in the Park',
            fake()->city().' Marathon '.fake()->year(),
            'Art Exhibition: '.fake()->catchPhrase(),
            'Startup Pitch Night',
            'Film Screening: '.fake()->sentence(3),
            'Weekend Farmers Market',
            'Comedy Show with '.fake()->name(),
            'Book Club: '.fake()->sentence(2),
        ];

        $startsAt = fake()->dateTimeBetween('now', '+30 days');

        return [
            'title' => fake()->randomElement($titlePatterns),
            'description' => fake()->paragraphs(2, true),
            'source' => fake()->randomElement(['eventbrite', 'meetup', 'generic_html', 'rss_feed']),
            'source_url' => fake()->unique()->url(),
            'source_id' => fake()->uuid(),
            'category' => fake()->randomElement(EventCategory::cases()),
            'tags' => fake()->randomElements(
                ['live-music', 'jazz', 'outdoor', 'free', 'family-friendly', 'tech', 'workshop', 'food', 'art', 'startup', 'networking'],
                fake()->numberBetween(1, 4)
            ),
            'venue' => fake()->company().' '.fake()->randomElement(['Hall', 'Arena', 'Center', 'Café', 'Park', 'Gallery']),
            'address' => fake()->streetAddress(),
            'city' => 'Bucharest',
            'latitude' => fake()->latitude(44.38, 44.50),
            'longitude' => fake()->longitude(25.95, 26.15),
            'starts_at' => $startsAt,
            'ends_at' => Carbon::parse($startsAt)->addHours(rand(1, 5)),
            'price_min' => fake()->optional(0.6)->randomFloat(2, 0, 100),
            'price_max' => fn (array $attrs) => $attrs['price_min'] !== null
                ? fake()->randomFloat(2, (float) $attrs['price_min'], (float) $attrs['price_min'] + 100)
                : null,
            'currency' => 'RON',
            'is_free' => fake()->boolean(30),
            'image_url' => fake()->imageUrl(),
            'metadata' => [],
            'popularity_score' => fake()->numberBetween(0, 100),
            'is_classified' => true,
            'is_geocoded' => true,
            'is_enriched' => true,
        ];
    }

    /**
     * Keep the derived identity columns consistent with whatever title, city
     * and start time a test ends up using, so dedup queries can find the row.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Event $event): void {
            $this->applyIdentityKeys($event);
        })->afterCreating(function (Event $event): void {
            $this->applyIdentityKeys($event);

            Event::withoutSyncingToSearch(fn () => $event->save());
        });
    }

    /**
     * Indicate that the event has been merged into another one.
     */
    public function merged(Event $canonical): static
    {
        return $this->state(fn (array $attributes): array => [
            'merged_into_id' => $canonical->id,
        ]);
    }

    /**
     * Indicate that the event is unclassified.
     */
    public function unclassified(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_classified' => false,
            'category' => EventCategory::Other,
        ]);
    }

    /**
     * Indicate that the event is in the past.
     */
    public function past(): static
    {
        $startsAt = fake()->dateTimeBetween('-30 days', '-1 day');

        return $this->state(fn (array $attributes) => [
            'starts_at' => $startsAt,
            'ends_at' => Carbon::parse($startsAt)->addHours(rand(1, 5)),
        ]);
    }

    private function applyIdentityKeys(Event $event): void
    {
        $timezone = (string) config(
            'eventpulse.cities.'.config('eventpulse.default_city').'.timezone',
            (string) config('app.timezone', 'UTC'),
        );

        $localDate = $event->starts_at === null
            ? null
            : EventTextNormalizer::localDate($event->starts_at->toDateTimeString(), $timezone);

        $event->city_slug = EventTextNormalizer::citySlug($event->city);
        $event->local_date = $localDate;
        $event->match_key = EventTextNormalizer::matchKey($event->title, $event->city, $localDate);
    }
}
