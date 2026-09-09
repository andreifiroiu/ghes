<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NotificationChannel;
use App\Enums\NotificationFrequency;
use App\Enums\NotificationType;
use App\Models\Event;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            // Pinned rather than left to the column default: every test written
            // before reminders existed means a digest, and that must not depend
            // on which value the schema happens to default to.
            'type' => NotificationType::Digest,
            'event_id' => null,
            'lead_minutes' => null,
            'channel' => fake()->randomElement(NotificationChannel::cases()),
            'frequency' => fake()->randomElement(NotificationFrequency::cases()),
            'event_ids' => [fake()->uuid(), fake()->uuid()],
            'discovery_event_ids' => [fake()->uuid()],
            'subject' => fake()->sentence(),
            'body_html' => '<p>'.fake()->paragraphs(2, true).'</p>',
            'sent_at' => null,
            'opened_at' => null,
        ];
    }

    /**
     * A reminder about one event, the shape ReminderComposer writes: the event
     * id lands in both `event_id` and `event_ids` so the renderer, the open
     * pixel and the impression logging need no branch.
     */
    public function reminder(?Event $event = null, int $leadMinutes = 180): static
    {
        return $this->state(function (array $attributes) use ($event, $leadMinutes): array {
            $event ??= Event::factory()->create();

            return [
                'type' => NotificationType::Reminder,
                'event_id' => $event->id,
                'lead_minutes' => $leadMinutes,
                'event_ids' => [$event->id],
                'discovery_event_ids' => [],
            ];
        });
    }

    /**
     * Indicate that the notification has been sent.
     */
    public function sent(): static
    {
        return $this->state(fn (array $attributes) => [
            'sent_at' => now(),
        ]);
    }
}
