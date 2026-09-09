<?php

declare(strict_types=1);

namespace App\Services\Notification\Presenters;

use App\Enums\NotificationType;
use App\Models\Notification;
use Illuminate\Contracts\Container\Container;

/**
 * The one place a notification's type turns into behaviour.
 *
 * Resolved through the container rather than constructed here so a presenter
 * can take its renderer as a constructor dependency, and so a test can swap one
 * out the way it swaps any other collaborator.
 */
class NotificationPresenters
{
    public function __construct(
        private readonly Container $container,
    ) {}

    public function for(Notification $notification): NotificationPresenter
    {
        /** @var NotificationPresenter */
        return $this->container->make(match ($notification->type) {
            NotificationType::Digest => DigestPresenter::class,
            NotificationType::Reminder => ReminderPresenter::class,
        });
    }
}
