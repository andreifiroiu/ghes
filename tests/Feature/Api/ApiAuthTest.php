<?php

declare(strict_types=1);

use App\DTOs\DeviceContext;
use App\Enums\TokenAbility;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Auth\TokenIssuer;
use Illuminate\Support\Facades\Auth;

/**
 * @return array<string, string>
 */
function deviceFields(string $deviceId = '11111111-1111-4111-8111-111111111111'): array
{
    return ['device_id' => $deviceId, 'device_name' => 'Test phone', 'platform' => 'ios', 'app_version' => '1.0.0'];
}

/**
 * Sign in through the API and return the decoded pair.
 *
 * @return array<string, mixed>
 */
function signIn(User $user, string $deviceId = '11111111-1111-4111-8111-111111111111'): array
{
    return test()->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        ...deviceFields($deviceId),
    ])->assertOk()->json('data');
}

describe('issuing', function () {
    it('registers a new user and returns a token pair bound to the device', function () {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'email' => 'new@example.com',
            'password' => 'password123',
            ...deviceFields(),
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => ['access_token', 'access_expires_at', 'refresh_token', 'refresh_expires_at', 'device_id', 'user' => ['id', 'email']]])
            ->assertJsonPath('data.device_id', '11111111-1111-4111-8111-111111111111');

        $this->assertDatabaseHas('users', ['email' => 'new@example.com']);
    });

    it('requires a device on register and login', function () {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/register', ['name' => 'N', 'email' => 'x@example.com', 'password' => 'password123'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['device_name', 'platform']]]);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'p', 'platform' => 'toaster'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['platform']]]);
    });

    it('mints a device id when the client has none yet', function () {
        $user = User::factory()->create();

        $data = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'Fresh install',
            'platform' => 'android',
        ])->assertOk()->json('data');

        expect($data['device_id'])->toBeUuid();
        expect($user->tokens()->where('device_id', $data['device_id'])->count())->toBe(2);
    });

    it('issues an access and a refresh token with disjoint abilities and their own expiry', function () {
        config(['eventpulse.api.tokens.access_ttl_minutes' => 30, 'eventpulse.api.tokens.refresh_ttl_days' => 10]);
        $this->freezeTime();
        $user = User::factory()->create();

        $data = signIn($user);

        expect($data['access_expires_at'])->toBe(now()->addMinutes(30)->toIso8601String())
            ->and($data['refresh_expires_at'])->toBe(now()->addDays(10)->toIso8601String());

        $access = PersonalAccessToken::findToken($data['access_token']);
        $refresh = PersonalAccessToken::findToken($data['refresh_token']);

        expect($access->abilities)->toBe([TokenAbility::AccessApi->value])
            ->and($access->expires_at->toIso8601String())->toBe($data['access_expires_at'])
            ->and($access->device_name)->toBe('Test phone')
            ->and($access->platform->value)->toBe('ios')
            ->and($refresh->abilities)->toBe([TokenAbility::RefreshToken->value])
            ->and($refresh->expires_at->toIso8601String())->toBe($data['refresh_expires_at'])
            ->and($refresh->device_id)->toBe($access->device_id);
    });

    it('grants the admin ability only to admins', function () {
        $admin = User::factory()->create();
        config(['eventpulse.admin_emails' => [$admin->email]]);
        $user = User::factory()->create();

        $adminAccess = PersonalAccessToken::findToken(signIn($admin)['access_token']);
        $userAccess = PersonalAccessToken::findToken(signIn($user, '22222222-2222-4222-8222-222222222222')['access_token']);

        expect($adminAccess->abilities)->toContain(TokenAbility::Admin->value)
            ->and($userAccess->abilities)->not->toContain(TokenAbility::Admin->value);
    });

    it('replaces the pair when the same device signs in again', function () {
        $user = User::factory()->create();

        $first = signIn($user);
        app('auth')->forgetGuards();
        signIn($user);

        expect($user->tokens()->count())->toBe(2);
        $this->withToken($first['access_token'])->getJson('/api/v1/profile')->assertStatus(401);
    });

    it('rejects invalid credentials', function () {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
            ...deviceFields(),
        ])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    });
});

describe('using the pair', function () {
    it('lets the access token read but not refresh', function () {
        $user = User::factory()->create();
        $data = signIn($user);

        $this->withToken($data['access_token'])->getJson('/api/v1/profile')->assertOk();

        app('auth')->forgetGuards();
        $this->withToken($data['access_token'])->postJson('/api/v1/auth/refresh')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    });

    it('lets the refresh token refresh but not read', function () {
        $user = User::factory()->create();
        $data = signIn($user);

        $this->withToken($data['refresh_token'])->getJson('/api/v1/profile')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    });

    it('rotates the pair on refresh and revokes the old one', function () {
        $user = User::factory()->create();
        $old = signIn($user);

        app('auth')->forgetGuards();
        $new = $this->withToken($old['refresh_token'])->postJson('/api/v1/auth/refresh')
            ->assertOk()
            ->assertJsonPath('data.device_id', $old['device_id'])
            ->json('data');

        expect($new['access_token'])->not->toBe($old['access_token'])
            ->and($user->tokens()->count())->toBe(2)
            ->and(PersonalAccessToken::findToken($old['access_token']))->toBeNull()
            ->and(PersonalAccessToken::findToken($old['refresh_token']))->toBeNull();

        app('auth')->forgetGuards();
        $this->withToken($new['access_token'])->getJson('/api/v1/profile')->assertOk();
    });

    it('answers a replayed refresh token with 401', function () {
        $user = User::factory()->create();
        $old = signIn($user);

        app('auth')->forgetGuards();
        $this->withToken($old['refresh_token'])->postJson('/api/v1/auth/refresh')->assertOk();

        app('auth')->forgetGuards();
        $this->withToken($old['refresh_token'])->postJson('/api/v1/auth/refresh')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    });

    it('refuses to rotate a refresh token whose row is already gone', function () {
        // The race: two refreshes from one device authenticate against the same
        // row; the loser must not revoke the winner's fresh pair.
        $user = User::factory()->create();
        $data = signIn($user);
        $refresh = PersonalAccessToken::findToken($data['refresh_token']);

        $user->tokens()->whereKey($refresh->getKey())->delete();

        expect(app(TokenIssuer::class)->rotate($user, $refresh))->toBeNull()
            ->and($user->tokens()->count())->toBe(1);
    });

    it('refuses to rotate a token that was never bound to a device', function () {
        $user = User::factory()->create();
        $legacy = $user->createToken('api')->plainTextToken;

        $this->withToken($legacy)->postJson('/api/v1/auth/refresh')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    });

    it('never derives a device from a token without one', function () {
        $user = User::factory()->create();
        $legacy = PersonalAccessToken::findToken($user->createToken('api')->plainTextToken);

        expect(fn () => DeviceContext::fromToken($legacy))->toThrow(LogicException::class);
    });

    it('keeps the original sign-in time across rotations', function () {
        $this->freezeTime();
        $user = User::factory()->create();
        $first = signIn($user);
        $signedInAt = now()->toIso8601String();

        $this->travel(3)->hours();
        app('auth')->forgetGuards();
        $rotated = $this->withToken($first['refresh_token'])->postJson('/api/v1/auth/refresh')->assertOk()->json('data');

        app('auth')->forgetGuards();
        $sessions = $this->withToken($rotated['access_token'])->getJson('/api/v1/auth/sessions')->assertOk()->json('data');

        expect($sessions[0]['signed_in_at'])->toBe($signedInAt);
    });

    it('answers a session-authenticated caller with 401 on the token routes', function () {
        // Only a bearer has a pair to rotate or revoke; a web session does not.
        $this->actingAs(User::factory()->create())->postJson('/api/v1/auth/logout')
            ->assertStatus(401);
    });

    it('reports an expired access token as token_expired', function () {
        config(['eventpulse.api.tokens.access_ttl_minutes' => 5]);
        $user = User::factory()->create();
        $data = signIn($user);

        $this->travel(6)->minutes();
        app('auth')->forgetGuards();

        $this->withToken($data['access_token'])->getJson('/api/v1/profile')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'token_expired');
    });

    it('reports an expired refresh token as unauthenticated, not token_expired', function () {
        // token_expired means "refresh"; a refresh token past its life must
        // say "sign in again" or the client would loop on refresh forever.
        config(['eventpulse.api.tokens.refresh_ttl_days' => 1]);
        $user = User::factory()->create();
        $data = signIn($user);

        $this->travel(2)->days();
        app('auth')->forgetGuards();

        $this->withToken($data['refresh_token'])->postJson('/api/v1/auth/refresh')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    });

    it('answers a malformed bearer with unauthenticated rather than looking it up', function () {
        // `abc|x` would be a type error against Postgres's bigint id column.
        $this->withToken('abc|not-a-token')->getJson('/api/v1/profile')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
        $this->withToken('|')->getJson('/api/v1/profile')->assertStatus(401);
    });

    it('keeps sanctum global expiration off so refresh tokens outlive access tokens', function () {
        expect(config('sanctum.expiration'))->toBeNull();
    });
});

describe('signing out', function () {
    it('logs out only the current device', function () {
        $user = User::factory()->create();
        $phone = signIn($user, '11111111-1111-4111-8111-111111111111');
        app('auth')->forgetGuards();
        $tablet = signIn($user, '22222222-2222-4222-8222-222222222222');

        app('auth')->forgetGuards();
        $this->withToken($phone['access_token'])->postJson('/api/v1/auth/logout')->assertOk();

        expect($user->tokens()->where('device_id', $phone['device_id'])->count())->toBe(0)
            ->and($user->tokens()->where('device_id', $tablet['device_id'])->count())->toBe(2);

        app('auth')->forgetGuards();
        $this->withToken($phone['refresh_token'])->postJson('/api/v1/auth/refresh')->assertStatus(401);
    });

    it('logs out everywhere', function () {
        $user = User::factory()->create();
        signIn($user, '11111111-1111-4111-8111-111111111111');
        app('auth')->forgetGuards();
        $tablet = signIn($user, '22222222-2222-4222-8222-222222222222');

        app('auth')->forgetGuards();
        $this->withToken($tablet['access_token'])->postJson('/api/v1/auth/logout-all')->assertOk();

        expect($user->tokens()->count())->toBe(0);
    });

    it('logging out everywhere also kills the browser remember cookie', function () {
        $user = User::factory()->create();

        // The web half: a browser that ticked "remember me".
        $login = test()->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => true,
        ]);
        $recaller = $login->getCookie(Auth::guard('web')->getRecallerName())?->getValue();
        expect($recaller)->not->toBeNull();

        app('auth')->forgetGuards();
        $phone = signIn($user, '22222222-2222-4222-8222-222222222222');

        app('auth')->forgetGuards();
        test()->withToken($phone['access_token'])->postJson('/api/v1/auth/logout-all')->assertOk();

        // "Everywhere" has to mean the laptop too. Deleting Sanctum rows alone
        // would leave this cookie signing the user in for its full 400 days —
        // exactly the device the user reached for this endpoint to cut off.
        test()->flushSession();
        app('auth')->forgetGuards();

        test()->withCookie(Auth::guard('web')->getRecallerName(), $recaller)
            ->get('/dashboard')
            ->assertRedirect(route('login'));
    });

    it('lists the signed-in devices with the current one flagged', function () {
        $user = User::factory()->create();
        signIn($user, '11111111-1111-4111-8111-111111111111');
        app('auth')->forgetGuards();
        $tablet = signIn($user, '22222222-2222-4222-8222-222222222222');

        app('auth')->forgetGuards();
        $response = $this->withToken($tablet['access_token'])->getJson('/api/v1/auth/sessions')->assertOk();

        expect($response->json('data'))->toHaveCount(2);

        $current = collect($response->json('data'))->firstWhere('is_current', true);
        expect($current['device_id'])->toBe($tablet['device_id'])
            ->and($current)->toHaveKeys(['device_id', 'device_name', 'platform', 'app_version', 'signed_in_at', 'last_used_at', 'is_current']);
    });
});
