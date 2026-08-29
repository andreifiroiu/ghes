<?php

declare(strict_types=1);

use App\Enums\ActivityType;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserActivityLog;
use Illuminate\Support\Facades\URL;

function openPixelUrl(Notification $notification): string
{
    return URL::signedRoute('notifications.open', ['notification' => $notification->id]);
}

it('returns a gif and records the open', function () {
    $notification = Notification::factory()->for(User::factory())->create(['opened_at' => null]);

    $response = $this->withHeaders(browserHeaders())->get(openPixelUrl($notification));

    $response->assertOk()->assertHeader('Content-Type', 'image/gif');

    expect($notification->fresh()->opened_at)->not->toBeNull();

    $log = UserActivityLog::sole();
    expect($log->type)->toBe(ActivityType::EmailOpen)
        ->and($log->notification_id)->toBe($notification->id);
});

it('keeps the first open time when the mail is reopened', function () {
    $firstOpen = now()->subDays(2);
    $notification = Notification::factory()->for(User::factory())->create(['opened_at' => $firstOpen]);

    $this->get(openPixelUrl($notification))->assertOk();

    // Clients refetch images every time the message is displayed. Overwriting
    // would turn "when did this land" into "when was it last looked at".
    expect($notification->fresh()->opened_at->timestamp)->toBe($firstOpen->timestamp);
});

it('records every open, not just the first', function () {
    $notification = Notification::factory()->for(User::factory())->create(['opened_at' => null]);

    $this->get(openPixelUrl($notification))->assertOk();
    $this->get(openPixelUrl($notification))->assertOk();

    expect(UserActivityLog::ofType(ActivityType::EmailOpen)->count())->toBe(2);
});

it('tells the client not to cache the pixel', function () {
    $notification = Notification::factory()->for(User::factory())->create();

    // A cached pixel means every open after the first is invisible.
    $this->get(openPixelUrl($notification))
        ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private');
});

it('rejects an unsigned or tampered pixel url', function () {
    $notification = Notification::factory()->for(User::factory())->create(['opened_at' => null]);

    $this->get("/e/o/{$notification->id}.gif")->assertForbidden();
    $this->get(openPixelUrl($notification).'&tampered=1')->assertForbidden();

    expect($notification->fresh()->opened_at)->toBeNull()
        ->and(UserActivityLog::count())->toBe(0);
});

it('counts an image-proxy fetch as a real open', function () {
    $notification = Notification::factory()->for(User::factory())->create(['opened_at' => null]);

    // Gmail proxies every image, and it fetches when the reader opens the
    // message — so this request *is* the open. An image proxy only ever
    // fetches <img>, never a link, so it cannot inflate a click either.
    $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (via ggpht.com GoogleImageProxy)'])
        ->get(openPixelUrl($notification))
        ->assertOk();

    expect($notification->fresh()->opened_at)->not->toBeNull()
        ->and(UserActivityLog::sole()->is_bot)->toBeFalse();
});

it('does not let a delivery-time scanner stamp a false open', function () {
    $notification = Notification::factory()->for(User::factory())->create(['opened_at' => null]);

    // Security scanners fetch every URL the moment a message is delivered,
    // long before anyone reads it. Letting one set opened_at would peg the
    // open rate near 100% for a reason unrelated to any reader.
    $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; ProofPoint URL Defense)'])
        ->get(openPixelUrl($notification))
        ->assertOk();

    expect($notification->fresh()->opened_at)->toBeNull()
        ->and(UserActivityLog::sole()->is_bot)->toBeTrue();
});
