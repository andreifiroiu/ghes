<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Reaction;
use App\Models\Event;
use App\Models\User;
use App\Models\UserEventReaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserEventReaction>
 */
class UserEventReactionFactory extends Factory
{
    protected $model = UserEventReaction::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event_id' => Event::factory(),
            'reaction' => fake()->randomElement(Reaction::cases()),
            'is_processed' => false,
        ];
    }

    /**
     * Indicate that the reaction has been processed.
     */
    public function processed(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_processed' => true,
        ]);
    }

    public function interested(): static
    {
        return $this->state(fn (array $attributes) => [
            'reaction' => Reaction::Interested,
        ]);
    }

    public function notInterested(): static
    {
        return $this->state(fn (array $attributes) => [
            'reaction' => Reaction::NotInterested,
        ]);
    }
}
