<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\DeviceContext;
use App\DTOs\TokenPair;
use App\Exceptions\InvalidGoogleIdToken;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\GoogleSignInRequest;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Auth\GoogleIdTokenVerifier;
use App\Services\Auth\SocialAccountLinker;
use App\Services\Auth\TokenIssuer;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly TokenIssuer $tokens,
        private readonly GoogleIdTokenVerifier $google,
        private readonly SocialAccountLinker $linker,
    ) {}

    /**
     * Register a new user and issue a token pair for the device.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $attributes = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'onboarding_completed' => false,
        ];

        // Absent means "no opinion" and the model fills the covered city;
        // an explicit null means "no city" and is honoured, which is the
        // contract User::booted() documents.
        if ($request->exists('city')) {
            $attributes['city'] = $validated['city'] ?? null;
        }

        $user = User::create($attributes);

        return $this->issued($user, $this->tokens->issuePair($user, DeviceContext::fromValidated($validated)), 201);
    }

    /**
     * Authenticate by credentials and issue a token pair for the device.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::where('email', $validated['email'])->first();

        if ($user === null || ! Hash::check($validated['password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        return $this->issued($user, $this->tokens->issuePair($user, DeviceContext::fromValidated($validated)));
    }

    /**
     * Exchange a Google ID token, obtained by the app through PKCE against
     * Google directly, for a token pair. Same identity rule as the web
     * callback; the verifier's email_verified check is what makes linking
     * by address safe.
     */
    public function google(GoogleSignInRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $identity = $this->google->verify($validated['id_token']);
        } catch (InvalidGoogleIdToken $e) {
            throw ValidationException::withMessages(['id_token' => [$e->getMessage()]]);
        }

        $user = $this->linker->findOrCreate($identity->email, $identity->name);

        return $this->issued($user, $this->tokens->issuePair($user, DeviceContext::fromValidated($validated)));
    }

    /**
     * Rotate the pair: the refresh token used here and its access sibling
     * are revoked, and a fresh pair for the same device is returned.
     *
     * A replayed refresh token simply 401s because its row is gone. Full
     * reuse detection — revoking the whole family when a rotated token
     * reappears — needs retained rotated rows and is deliberately not in
     * Phase 1.
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();

        $pair = $this->tokens->rotate($user, $this->currentToken($request));

        // Gone between the guard and the lock (a racing refresh won), or a
        // token that was never bound to a device: nothing to rotate.
        if ($pair === null) {
            throw new AuthenticationException;
        }

        return $this->issued($user, $pair);
    }

    /**
     * Sign out this device: both tokens bound to it go.
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $this->currentToken($request);

        if ($token->device_id !== null) {
            $this->tokens->revokeDevice($request->user(), $token->device_id);

            // The push registration made from this install goes too;
            // otherwise the digest keeps landing on a signed-out phone.
            $request->user()->devices()->where('install_id', $token->device_id)->delete();
        } else {
            $token->delete();
        }

        return ApiResponse::message('Logged out.');
    }

    /**
     * Sign out everywhere.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $this->tokens->revokeAll($request->user());

        // "Everywhere" includes every phone's push registration; a signed-out
        // handset must not keep receiving the digest.
        $request->user()->devices()->delete();

        return ApiResponse::message('Logged out everywhere.');
    }

    /**
     * The devices holding a live pair, most recently active first.
     */
    public function sessions(Request $request): JsonResponse
    {
        $current = $this->currentToken($request)->device_id;

        /** @var Collection<int, PersonalAccessToken> $tokens */
        $tokens = $request->user()->tokens()
            ->whereNotNull('device_id')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get();

        $devices = $tokens
            ->groupBy('device_id')
            ->map(function ($group, string $deviceId) use ($current): array {
                /** @var Collection<int, PersonalAccessToken> $group */
                $latest = $group->sortByDesc('created_at')->first();

                return [
                    'device_id' => $deviceId,
                    'device_name' => $latest->device_name,
                    'platform' => $latest->platform?->value,
                    'app_version' => $latest->app_version,
                    'signed_in_at' => $group->min('signed_in_at')?->toIso8601String(),
                    'last_used_at' => $group->max('last_used_at')?->toIso8601String(),
                    'is_current' => $deviceId === $current,
                ];
            })
            ->sortByDesc('last_used_at')
            ->values()
            ->all();

        return ApiResponse::collection($devices);
    }

    private function issued(User $user, TokenPair $pair, int $status = 200): JsonResponse
    {
        return ApiResponse::item([
            ...$pair->toArray(),
            'user' => (new UserResource($user))->resolve(),
        ], $status);
    }

    /**
     * The persisted token behind this request.
     */
    private function currentToken(Request $request): PersonalAccessToken
    {
        $token = $request->user()->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            // A transient token means the request authenticated through a
            // session, not a bearer — there is no pair to rotate or revoke.
            throw new AuthenticationException;
        }

        return $token;
    }
}
