<?php

declare(strict_types=1);

use App\Http\Resources\EventResource;
use App\Http\Responses\ApiResponse;
use App\Models\Event;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;

describe('success envelope', function () {
    it('wraps every single resource under data', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create(['starts_at' => now()->addDay()]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonStructure(['data' => ['id', 'email', 'onboarding_completed']])
            ->assertJsonMissingPath('id');

        $this->getJson("/api/v1/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $event->id)
            ->assertJsonStructure(['data' => ['related_events']])
            ->assertJsonMissingPath('relatedEvents');

        $this->getJson('/api/v1/recommendations')
            ->assertOk()
            ->assertJsonStructure(['data' => ['recommendations', 'discovery', 'total_score']]);

        $this->getJson('/api/v1/profile/stats')
            ->assertOk()
            ->assertJsonStructure(['data' => ['reactions', 'discovery']]);
    });

    it('wraps acknowledgements under data too', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/feedback', ['event_id' => $event->id, 'reaction' => 'interested'])
            ->assertOk()
            ->assertExactJson(['data' => ['message' => 'Feedback recorded.', 'reaction' => 'interested']]);

        $this->postJson('/api/v1/bookmarks', ['event_id' => $event->id])
            ->assertOk()
            ->assertExactJson(['data' => ['message' => 'Event saved.']]);

        $this->deleteJson('/api/v1/bookmarks', ['event_id' => $event->id])
            ->assertOk()
            ->assertExactJson(['data' => ['message' => 'Event unsaved.']]);

        $this->deleteJson('/api/v1/feedback', ['event_id' => $event->id])
            ->assertOk()
            ->assertExactJson(['data' => ['message' => 'Feedback removed.']]);
    });

    it('renders every paginated list as data, links and meta', function () {
        $user = User::factory()->create();
        Event::factory()->count(2)->create(['starts_at' => now()->addDay()]);
        Notification::factory()->count(2)->create(['user_id' => $user->id, 'sent_at' => now()]);
        Sanctum::actingAs($user);

        foreach (['/api/v1/events', '/api/v1/events/saved', '/api/v1/notifications', '/api/v1/recommendations/history'] as $path) {
            $response = $this->getJson($path)->assertOk();

            $response->assertJsonStructure([
                'data',
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'last_page', 'per_page', 'total', 'links'],
            ]);

            // `links` is an object, the page links are the array at meta.links —
            // the confusion that white-screened two admin pages.
            expect($response->json('meta.links'))->toBeList()
                ->and($response->json('links'))->toBeArray()->toHaveKey('next');
        }
    });

    it('serialises every timestamp as ISO-8601', function () {
        $user = User::factory()->create([
            'email_verified_at' => '2026-03-01 10:00:00',
            'profile_summary_updated_at' => '2026-03-02 11:30:00',
        ]);
        Notification::factory()->create([
            'user_id' => $user->id,
            'sent_at' => '2026-03-03 08:00:00',
            'opened_at' => null,
        ]);
        Sanctum::actingAs($user);

        $iso = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/';

        $profile = $this->getJson('/api/v1/profile')->assertOk();
        expect($profile->json('data.email_verified_at'))->toMatch($iso)
            ->and($profile->json('data.profile_summary_updated_at'))->toMatch($iso);

        $notifications = $this->getJson('/api/v1/notifications')->assertOk();
        expect($notifications->json('data.0.sent_at'))->toMatch($iso)
            ->and($notifications->json('data.0.opened_at'))->toBeNull();
    });

    it('paginates saved events at the configured page size', function () {
        config(['eventpulse.pagination.events' => 2]);
        $user = User::factory()->create();
        Event::factory()->count(3)->create(['starts_at' => now()->addDay()])
            ->each(fn (Event $event) => $user->bookmarks()->create(['event_id' => $event->id]));
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/events/saved')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3);
    });
});

describe('error envelope', function () {
    it('shapes validation failures', function () {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/feedback', ['reaction' => 'nope'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['event_id', 'reaction']]]);

        expect($response->json())->not->toHaveKey('errors');
    });

    it('shapes unauthenticated, forbidden and not found', function () {
        $this->getJson('/api/v1/profile')
            ->assertStatus(401)
            ->assertExactJson(['error' => ['code' => 'unauthenticated', 'message' => 'Unauthenticated.', 'details' => null]]);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/events/stats')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');

        $this->getJson('/api/v1/events/'.fake()->uuid())
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');
    });

    it('shapes rate limiting with a retry hint', function () {
        config(['eventpulse.api.throttle.per_minute' => 1]);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/profile')->assertOk();

        $this->getJson('/api/v1/profile')
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'rate_limited')
            ->assertJsonStructure(['error' => ['code', 'message', 'details', 'retry_after']]);
    });

    it('lets a thrown response through untouched', function () {
        Sanctum::actingAs(User::factory()->create());

        Route::get('/api/v1/teapot', fn () => throw new HttpResponseException(response()->json(['data' => ['tea' => true]], 418)))
            ->middleware('api');

        $this->getJson('/api/v1/teapot')
            ->assertStatus(418)
            ->assertExactJson(['data' => ['tea' => true]]);
    });

    it('refuses to render a plain collection as a page', function () {
        expect(fn () => ApiResponse::paginated(EventResource::collection(collect([]))))
            ->toThrow(LogicException::class);
    });

    it('keeps a maintenance 503 as a 503 with its retry hint', function () {
        Sanctum::actingAs(User::factory()->create());

        // What PreventRequestsDuringMaintenance raises for `artisan down --retry=600`.
        Route::get('/api/v1/down', fn () => throw new HttpException(503, 'Service Unavailable', null, ['Retry-After' => 600]))
            ->middleware('api');

        $this->getJson('/api/v1/down')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '600')
            ->assertJsonPath('error.code', 'service_unavailable')
            ->assertJsonPath('error.retry_after', 600);
    });

    it('derives the code from the status for other http exceptions', function () {
        Sanctum::actingAs(User::factory()->create());

        // What Response::denyAsNotFound() becomes after prepareException(): a
        // plain HttpException with a 404 status, not a NotFoundHttpException.
        Route::get('/api/v1/denied-as-missing', fn () => throw new HttpException(404, 'Hidden on purpose'))->middleware('api');
        Route::post('/api/v1/post-only', fn () => [])->middleware('api');

        $this->getJson('/api/v1/denied-as-missing')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found')
            ->assertJsonPath('error.message', 'Hidden on purpose');

        $this->getJson('/api/v1/post-only')
            ->assertStatus(405)
            ->assertJsonPath('error.code', 'bad_request');
    });

    it('keeps a gate deny message', function () {
        Sanctum::actingAs(User::factory()->create());

        Route::get('/api/v1/suspended', fn () => throw new AuthorizationException('Your account is suspended.'))
            ->middleware('api');

        $this->getJson('/api/v1/suspended')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden')
            ->assertJsonPath('error.message', 'Your account is suspended.');
    });

    it('shapes an unhandled exception as a server error without leaking it', function () {
        config(['app.debug' => false]);
        Sanctum::actingAs(User::factory()->create());

        Route::get('/api/v1/boom', fn () => throw new RuntimeException('secret detail'))
            ->middleware('api');

        $this->getJson('/api/v1/boom')
            ->assertStatus(500)
            ->assertExactJson(['error' => ['code' => 'server_error', 'message' => 'Server error.', 'details' => null]]);
    });
});

describe('the web frontend is untouched', function () {
    // The renderer is scoped by path, not by expectsJson(): the web pages
    // fetch() these same controllers and parse the JSON they have always
    // returned. This is the regression the scoping exists to prevent.
    it('leaves the web feedback JSON byte-for-byte unchanged', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create();

        $this->actingAs($user)->postJson('/feedback', ['event_id' => $event->id, 'reaction' => 'interested'])
            ->assertOk()
            ->assertExactJson(['message' => 'Feedback recorded.', 'reaction' => 'interested']);

        $this->actingAs($user)->postJson('/bookmarks', ['event_id' => $event->id])
            ->assertOk()
            ->assertExactJson(['message' => 'Event saved.']);
    });

    it('leaves the web validation error shape unchanged', function () {
        $this->actingAs(User::factory()->create())
            ->postJson('/feedback', ['reaction' => 'nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['event_id', 'reaction'])
            ->assertJsonMissingPath('error');
    });

    it('leaves the web unauthenticated response unchanged', function () {
        $this->postJson('/feedback', [])
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthenticated.']);
    });
});
