<?php

declare(strict_types=1);

use App\Enums\TokenAbility;
use App\Http\Controllers\Api\AdminStatsController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EventClickController;
use App\Http\Controllers\Api\V1\MetaController;
use App\Http\Controllers\BookmarkController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\NotificationSettingsController;
use App\Http\Controllers\RecommendationController;
use Illuminate\Support\Facades\Route;

// Version 1 of the API. Mounted by routes/api.php under `/api/v1` with the
// `api.v1.` name prefix, so every name here is relative. Every route in this
// file already carries the `api` group's `throttle:api`; the public set below
// is the only part reachable without a bearer token, and
// tests/Feature/Api/ApiRouteGuardsTest.php holds that line.
//
// Tokens come in pairs with disjoint abilities: the access token can do
// everything under `abilities:api:access` and nothing else; the refresh token
// can only reach `auth/refresh`. A stolen access token therefore cannot mint
// a new one, and a stolen refresh token cannot read anything.

// Public: what the app needs before sign-in.
Route::get('meta', MetaController::class)->name('meta');
Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:api-register')->name('auth.register');
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:api-auth')->name('auth.login');

// Refresh token only.
Route::post('auth/refresh', [AuthController::class, 'refresh'])
    ->middleware(['auth:sanctum', 'ability:'.TokenAbility::RefreshToken->value, 'throttle:api-refresh'])
    ->name('auth.refresh');

Route::middleware(['auth:sanctum', 'abilities:'.TokenAbility::AccessApi->value])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::post('auth/logout-all', [AuthController::class, 'logoutAll'])->name('auth.logout-all');
    Route::get('auth/sessions', [AuthController::class, 'sessions'])->name('auth.sessions');

    Route::get('events', [EventController::class, 'apiIndex'])->name('events.index');
    Route::get('events/saved', [BookmarkController::class, 'apiIndex'])->name('events.saved');
    Route::get('events/{event}', [EventController::class, 'apiShow'])->whereUuid('event')->name('events.show');
    // The authenticated twin of the public `go/{event}` redirect: logs the
    // click, nudges the profile, returns the URL for the client to open.
    Route::post('events/{event}/click', EventClickController::class)->whereUuid('event')->name('events.click');

    Route::get('recommendations', [RecommendationController::class, 'apiIndex'])->name('recommendations');
    Route::get('recommendations/history', [RecommendationController::class, 'apiHistory'])->name('recommendations.history');

    Route::post('feedback', [FeedbackController::class, 'apiStore'])->name('feedback.store');
    Route::delete('feedback', [FeedbackController::class, 'apiDestroy'])->name('feedback.destroy');
    Route::post('bookmarks', [BookmarkController::class, 'apiStore'])->name('bookmarks.store');
    Route::delete('bookmarks', [BookmarkController::class, 'apiDestroy'])->name('bookmarks.destroy');

    Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('profile/stats', [ProfileController::class, 'stats'])->name('profile.stats');

    // Self-service deletion: required by both stores for any app that can
    // create an account. Throttled like a sign-in: it checks a password, so
    // a stolen access token must not get to guess one at the general rate.
    Route::delete('account', [AccountController::class, 'destroy'])->middleware('throttle:api-reauth')->name('account.destroy');

    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('chat/history', [ChatController::class, 'apiHistory'])->name('chat.history');

    // The two LLM chats. Every POST here costs a Claude call, hence the
    // per-user limiter with a daily ceiling on top of the minute one.
    Route::get('onboarding', [ChatController::class, 'apiOnboarding'])->name('onboarding.show');
    Route::post('onboarding/chat', [ChatController::class, 'apiStore'])->middleware('throttle:api-chat')->name('onboarding.chat');
    Route::post('onboarding/confirm', [ChatController::class, 'apiConfirmProfile'])->middleware('throttle:api-chat')->name('onboarding.confirm');
    Route::get('profile/chat', [ChatController::class, 'apiProfileChat'])->name('profile.chat.show');
    Route::post('profile/chat', [ChatController::class, 'apiProfileChatStore'])->middleware('throttle:api-chat')->name('profile.chat.store');
    Route::post('profile/chat/apply', [ChatController::class, 'apiApplyProfileUpdate'])->middleware('throttle:api-chat')->name('profile.chat.apply');

    Route::get('settings/notifications', [NotificationSettingsController::class, 'apiShow'])->name('settings.notifications.show');
    Route::put('settings/notifications', [NotificationSettingsController::class, 'apiUpdate'])->name('settings.notifications.update');

    // The gate checks the user; the ability checks the token. Both, so an
    // admin's token issued before they were made admin does not gain the
    // scope until it is reissued.
    Route::prefix('admin')->name('admin.')->middleware(['can:access-admin', 'abilities:'.TokenAbility::Admin->value])->group(function () {
        Route::get('events/stats', [AdminStatsController::class, 'eventStats'])->name('events.stats');
        Route::get('activity/stats', [AdminStatsController::class, 'activityStats'])->name('activity.stats');
    });
});
