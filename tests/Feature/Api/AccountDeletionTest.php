<?php

declare(strict_types=1);

use App\Models\ChatMessage;
use App\Models\DiscoveryLog;
use App\Models\Event;
use App\Models\EventBookmark;
use App\Models\Notification;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Models\UserEventReaction;
use App\Services\Auth\GoogleIdTokenVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * A user with a row in every table that hangs off users.
 */
function userWithEverything(): User
{
    $user = User::factory()->create();
    $event = Event::factory()->create();

    UserEventReaction::factory()->create(['user_id' => $user->id, 'event_id' => $event->id]);
    EventBookmark::factory()->create(['user_id' => $user->id, 'event_id' => $event->id]);
    Notification::factory()->create(['user_id' => $user->id]);
    ChatMessage::factory()->create(['user_id' => $user->id]);
    DiscoveryLog::factory()->create(['user_id' => $user->id, 'event_id' => $event->id]);
    UserActivityLog::factory()->create(['user_id' => $user->id, 'event_id' => $event->id]);
    PushSubscription::factory()->create(['user_id' => $user->id]);
    DB::table('sessions')->insert([
        'id' => 'sess-'.$user->id,
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => '',
        'last_activity' => time(),
    ]);
    $user->createToken('other-device', ['api:access']);

    return $user;
}

/**
 * @return array<string, int>
 */
function rowsOwnedBy(User $user): array
{
    return [
        'users' => User::whereKey($user->id)->count(),
        'user_event_reactions' => DB::table('user_event_reactions')->where('user_id', $user->id)->count(),
        'event_bookmarks' => DB::table('event_bookmarks')->where('user_id', $user->id)->count(),
        'event_notifications' => DB::table('event_notifications')->where('user_id', $user->id)->count(),
        'chat_messages' => DB::table('chat_messages')->where('user_id', $user->id)->count(),
        'discovery_logs' => DB::table('discovery_logs')->where('user_id', $user->id)->count(),
        'user_activity_logs' => DB::table('user_activity_logs')->where('user_id', $user->id)->count(),
        'push_subscriptions' => DB::table('push_subscriptions')->where('user_id', $user->id)->count(),
        'sessions' => DB::table('sessions')->where('user_id', $user->id)->count(),
        'personal_access_tokens' => DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count(),
    ];
}

it('deletes the account and every row it owns', function () {
    $user = userWithEverything();
    $pair = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email, 'password' => 'password', 'device_name' => 'phone', 'platform' => 'ios',
    ])->assertOk()->json('data');

    $before = rowsOwnedBy($user);
    expect(array_filter($before, fn (int $n) => $n === 0))->toBe([], 'the fixture must populate every table');

    $this->withToken($pair['access_token'])
        ->deleteJson('/api/v1/account', ['current_password' => 'password'])
        ->assertOk()
        ->assertExactJson(['data' => ['message' => 'Account deleted.']]);

    expect(array_filter(rowsOwnedBy($user)))->toBe([], 'rows left behind');

    // The event itself is not the user's and survives.
    expect(Event::count())->toBe(1);
});

it('refuses a wrong password and deletes nothing', function () {
    $user = userWithEverything();
    $pair = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email, 'password' => 'password', 'device_name' => 'phone', 'platform' => 'ios',
    ])->json('data');

    $this->withToken($pair['access_token'])
        ->deleteJson('/api/v1/account', ['current_password' => 'not-it'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['current_password']]]);

    expect(User::whereKey($user->id)->exists())->toBeTrue()
        ->and(rowsOwnedBy($user)['personal_access_tokens'])->toBe(3);
});

it('accepts a fresh google id token for the same address instead of a password', function () {
    config(['services.google.client_ids' => ['ios-client']]);
    $user = User::factory()->create(['email' => 'ana@gmail.com']);
    $pair = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email, 'password' => 'password', 'device_name' => 'phone', 'platform' => 'ios',
    ])->json('data');
    Http::fake([
        GoogleIdTokenVerifier::TOKENINFO_URL.'*' => Http::response([
            'iss' => 'https://accounts.google.com', 'aud' => 'ios-client', 'sub' => '1', 'email' => 'ana@gmail.com',
            'email_verified' => 'true', 'exp' => (string) (time() + 600),
        ]),
    ]);

    $this->withToken($pair['access_token'])
        ->deleteJson('/api/v1/account', ['google_id_token' => 'a.b.c'])
        ->assertOk();

    expect(User::whereKey($user->id)->exists())->toBeFalse();
});

it('refuses a google id token for a different address', function () {
    config(['services.google.client_ids' => ['ios-client']]);
    $user = User::factory()->create(['email' => 'ana@gmail.com']);
    $pair = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email, 'password' => 'password', 'device_name' => 'phone', 'platform' => 'ios',
    ])->json('data');
    Http::fake([
        GoogleIdTokenVerifier::TOKENINFO_URL.'*' => Http::response([
            'iss' => 'https://accounts.google.com', 'aud' => 'ios-client', 'sub' => '2', 'email' => 'someone-else@gmail.com',
            'email_verified' => 'true', 'exp' => (string) (time() + 600),
        ]),
    ]);

    $this->withToken($pair['access_token'])
        ->deleteJson('/api/v1/account', ['google_id_token' => 'a.b.c'])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['google_id_token']]]);

    expect(User::whereKey($user->id)->exists())->toBeTrue();
});

it('requires the password field', function () {
    $user = User::factory()->create();
    $pair = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email, 'password' => 'password', 'device_name' => 'phone', 'platform' => 'ios',
    ])->json('data');

    $this->withToken($pair['access_token'])
        ->deleteJson('/api/v1/account', [])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['current_password']]]);
});

it('throttles password guesses like a sign-in', function () {
    $user = User::factory()->create();
    $pair = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email, 'password' => 'password', 'device_name' => 'phone', 'platform' => 'ios',
    ])->json('data');

    foreach (range(1, 5) as $i) {
        $this->withToken($pair['access_token'])
            ->deleteJson('/api/v1/account', ['current_password' => "guess-{$i}"])
            ->assertStatus(422);
    }

    $this->withToken($pair['access_token'])
        ->deleteJson('/api/v1/account', ['current_password' => 'password'])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited');

    expect(User::whereKey($user->id)->exists())->toBeTrue();
});

it('leaves the deleted account unable to use its tokens', function () {
    $user = User::factory()->create();
    $pair = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email, 'password' => 'password', 'device_name' => 'phone', 'platform' => 'ios',
    ])->json('data');

    $this->withToken($pair['access_token'])->deleteJson('/api/v1/account', ['current_password' => 'password'])->assertOk();

    app('auth')->forgetGuards();
    $this->withToken($pair['access_token'])->getJson('/api/v1/profile')->assertStatus(401);
    app('auth')->forgetGuards();
    $this->withToken($pair['refresh_token'])->postJson('/api/v1/auth/refresh')->assertStatus(401);
});

it('is the same cleanup the admin panel uses', function () {
    $admin = User::factory()->create();
    config(['eventpulse.admin_emails' => [$admin->email]]);
    $user = userWithEverything();

    $this->actingAs($admin)->delete("/admin/users/{$user->id}")->assertRedirect(route('admin.users.index'));

    expect(array_filter(rowsOwnedBy($user)))->toBe([]);
});
