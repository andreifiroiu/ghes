<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EventCategory;
use App\Enums\NotificationChannel;
use App\Enums\NotificationFrequency;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $categories = EventCategory::cases();
        $interestProfile = [];
        foreach ($categories as $category) {
            $interestProfile[$category->value] = fake()->randomFloat(2, 0, 1);
        }

        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'interest_profile' => $interestProfile,
            'discovery_openness' => fake()->randomFloat(2, 0.1, 0.9),
            'notification_channel' => fake()->randomElement(NotificationChannel::cases()),
            'notification_frequency' => fake()->randomElement(NotificationFrequency::cases()),
            'timezone' => fake()->randomElement(['Europe/Bucharest', 'Europe/London', 'America/New_York', 'Europe/Berlin']),
            'city' => 'Bucharest',
            'onboarding_completed' => true,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the user has not completed onboarding.
     *
     * An empty profile, not null: the column is NOT NULL with a '{}' default,
     * and the model types the attribute as an array.
     */
    public function notOnboarded(): static
    {
        return $this->state(fn (array $attributes) => [
            'onboarding_completed' => false,
            'interest_profile' => [],
        ]);
    }
}
