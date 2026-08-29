<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ActivitySurface;
use App\Enums\ActivityType;
use App\Models\Event;
use App\Models\User;
use App\Models\UserActivityLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserActivityLog>
 */
class UserActivityLogFactory extends Factory
{
    protected $model = UserActivityLog::class;

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
            'notification_id' => null,
            'type' => ActivityType::EventClick,
            'surface' => ActivitySurface::EventsIndex,
            'session_key' => null,
            'is_bot' => false,
            'context' => [],
        ];
    }

    public function ofType(ActivityType $type): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type,
        ]);
    }

    public function on(ActivitySurface $surface): static
    {
        return $this->state(fn (array $attributes) => [
            'surface' => $surface,
        ]);
    }

    /**
     * A hit from a mail scanner or link prefetcher.
     */
    public function bot(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_bot' => true,
        ]);
    }

    /**
     * An anonymous hit — a guest browsing, or a signed email link opened
     * without a session.
     */
    public function guest(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => null,
            'session_key' => hash('sha256', (string) $this->faker->uuid()),
        ]);
    }
}
