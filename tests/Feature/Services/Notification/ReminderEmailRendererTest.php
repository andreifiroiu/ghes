<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Notification;
use App\Services\Notification\ReminderEmailRenderer;

it('shows the tags of the reminded event', function () {
    $event = Event::factory()->startingIn(175)->create(['tags' => ['jazz', 'outdoor']]);
    $reminder = Notification::factory()->reminder($event, 180)->create();

    $html = (new ReminderEmailRenderer)->render($reminder, $event);

    expect($html)->toContain('#jazz');
    expect($html)->toContain('#outdoor');
});

it('renders no tag row when the reminded event has no tags', function () {
    $event = Event::factory()->startingIn(175)->create(['tags' => []]);
    $reminder = Notification::factory()->reminder($event, 180)->create();

    $html = (new ReminderEmailRenderer)->render($reminder, $event);

    expect($html)->not->toContain('event-tags');
});
