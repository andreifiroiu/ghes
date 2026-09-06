<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * @return array<string, string>
 */
function deviceBody(string $token = 'ExponentPushToken[abc123]'): array
{
    return [
        'push_token' => $token,
        'platform' => 'ios',
        'install_id' => '33333333-3333-4333-8333-333333333333',
        'device_name' => 'Ana\'s iPhone',
        'app_version' => '1.0.0',
        'os_version' => '17.4',
        'locale' => 'ro-RO',
        'timezone' => 'Europe/Bucharest',
    ];
}

it('registers a device and refreshes it idempotently', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['*']);

    $this->postJson('/api/v1/devices', deviceBody())
        ->assertStatus(201)
        ->assertJsonPath('data.push_token', 'ExponentPushToken[abc123]')
        ->assertJsonPath('data.platform', 'ios')
        ->assertJsonPath('data.install_id', '33333333-3333-4333-8333-333333333333');

    $this->postJson('/api/v1/devices', [...deviceBody(), 'app_version' => '1.1.0'])
        ->assertOk()
        ->assertJsonPath('data.app_version', '1.1.0');

    expect(Device::count())->toBe(1)
        ->and($user->devices()->sole()->app_version)->toBe('1.1.0');
});

it('re-points a token registered by a second account and leaves none for the first', function () {
    // Handset resale or account switch: the push service hands the new
    // account the same token, and the old account's digest must not land
    // on this phone any more.
    $first = User::factory()->create();
    $second = User::factory()->create();

    Sanctum::actingAs($first, ['*']);
    $this->postJson('/api/v1/devices', deviceBody())->assertStatus(201);

    Sanctum::actingAs($second, ['*']);
    $this->postJson('/api/v1/devices', deviceBody())->assertOk();

    expect(Device::count())->toBe(1)
        ->and($first->devices()->count())->toBe(0)
        ->and($second->devices()->count())->toBe(1);
});

it('rejects a token the expo service cannot deliver to', function () {
    Sanctum::actingAs(User::factory()->create(), ['*']);

    foreach (['raw-fcm-token', 'ExponentPushToken[]', ''] as $token) {
        $this->postJson('/api/v1/devices', deviceBody($token))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['push_token']]]);
    }

    $this->postJson('/api/v1/devices', [...deviceBody(), 'platform' => 'toaster', 'timezone' => 'Mars/Olympus'])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['platform', 'timezone']]]);

    expect(Device::count())->toBe(0);
});

it('lists the account\'s devices', function () {
    $user = User::factory()->create();
    Device::factory()->count(2)->create(['user_id' => $user->id]);
    Device::factory()->create();
    Sanctum::actingAs($user, ['*']);

    $this->getJson('/api/v1/devices')->assertOk()->assertJsonCount(2, 'data');
});

it('forgets a device on sign-out, but only the caller\'s own', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $mine = Device::factory()->create(['user_id' => $user->id]);
    $theirs = Device::factory()->create(['user_id' => $other->id]);
    Sanctum::actingAs($user, ['*']);

    $this->deleteJson('/api/v1/devices', ['push_token' => $mine->push_token])->assertOk();
    $this->deleteJson('/api/v1/devices', ['push_token' => $theirs->push_token])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['push_token']]]);

    expect(Device::count())->toBe(1)->and(Device::find($theirs->id))->not->toBeNull();
});

it('drops this install\'s device on logout', function () {
    $user = User::factory()->create();
    $installId = '44444444-4444-4444-8444-444444444444';
    $pair = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email, 'password' => 'password', 'device_id' => $installId, 'device_name' => 'phone', 'platform' => 'ios',
    ])->assertOk()->json('data');
    Device::factory()->create(['user_id' => $user->id, 'install_id' => $installId]);
    $otherPhone = Device::factory()->create(['user_id' => $user->id]);

    $this->withToken($pair['access_token'])->postJson('/api/v1/auth/logout')->assertOk();

    expect($user->devices()->pluck('id')->all())->toBe([$otherPhone->id]);
});

it('removes devices with the account', function () {
    $user = User::factory()->create();
    Device::factory()->create(['user_id' => $user->id]);
    $pair = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email, 'password' => 'password', 'device_name' => 'phone', 'platform' => 'ios',
    ])->json('data');

    $this->withToken($pair['access_token'])->deleteJson('/api/v1/account', ['current_password' => 'password'])->assertOk();

    expect(Device::count())->toBe(0);
});

it('throttles registration per user', function () {
    config(['eventpulse.api.throttle.devices_per_minute' => 2]);
    Sanctum::actingAs(User::factory()->create(), ['*']);

    $this->postJson('/api/v1/devices', deviceBody())->assertStatus(201);
    $this->postJson('/api/v1/devices', deviceBody())->assertOk();
    $this->postJson('/api/v1/devices', deviceBody())->assertStatus(429);
});
