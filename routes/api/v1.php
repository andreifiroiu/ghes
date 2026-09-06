<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AdminStatsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\V1\MetaController;
use App\Http\Controllers\BookmarkController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\RecommendationController;
use Illuminate\Support\Facades\Route;

// Version 1 of the API. Mounted by routes/api.php under `/api/v1` with the
// `api.v1.` name prefix, so every name here is relative. Every route in this
// file already carries the `api` group's `throttle:api`; the public set below
// is the only part reachable without a bearer token, and
// tests/Feature/Api/ApiRouteGuardsTest.php holds that line.

// Public: what the app needs before sign-in.
Route::get('meta', MetaController::class)->name('meta');
Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1')->name('auth.register');
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('auth.login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

    Route::get('events', [EventController::class, 'apiIndex'])->name('events.index');
    Route::get('events/saved', [BookmarkController::class, 'apiIndex'])->name('events.saved');
    Route::get('events/{event}', [EventController::class, 'apiShow'])->whereUuid('event')->name('events.show');

    Route::get('recommendations', [RecommendationController::class, 'apiIndex'])->name('recommendations');
    Route::get('recommendations/history', [RecommendationController::class, 'apiHistory'])->name('recommendations.history');

    Route::post('feedback', [FeedbackController::class, 'apiStore'])->name('feedback.store');
    Route::delete('feedback', [FeedbackController::class, 'apiDestroy'])->name('feedback.destroy');
    Route::post('bookmarks', [BookmarkController::class, 'apiStore'])->name('bookmarks.store');
    Route::delete('bookmarks', [BookmarkController::class, 'apiDestroy'])->name('bookmarks.destroy');

    Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('profile/stats', [ProfileController::class, 'stats'])->name('profile.stats');

    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('chat/history', [ChatController::class, 'apiHistory'])->name('chat.history');

    Route::prefix('admin')->name('admin.')->middleware('can:access-admin')->group(function () {
        Route::get('events/stats', [AdminStatsController::class, 'eventStats'])->name('events.stats');
        Route::get('activity/stats', [AdminStatsController::class, 'activityStats'])->name('activity.stats');
    });
});
