<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AdminStatsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\RecommendationController;
use Illuminate\Support\Facades\Route;

// Public API auth (token issuance)
Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1')->name('api.auth.register');
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('api.auth.login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');

    Route::get('events', [EventController::class, 'apiIndex'])->name('api.events.index');
    Route::get('events/saved', [EventController::class, 'apiSaved'])->name('api.events.saved');
    Route::get('events/{event}', [EventController::class, 'apiShow'])->name('api.events.show');

    Route::get('recommendations', [RecommendationController::class, 'apiIndex'])->name('api.recommendations');
    Route::get('recommendations/history', [RecommendationController::class, 'apiHistory'])->name('api.recommendations.history');

    Route::post('feedback', [FeedbackController::class, 'store'])->name('api.feedback.store');
    Route::delete('feedback', [FeedbackController::class, 'destroy'])->name('api.feedback.destroy');

    Route::get('profile', [ProfileController::class, 'show'])->name('api.profile.show');
    Route::put('profile', [ProfileController::class, 'update'])->name('api.profile.update');
    Route::get('profile/stats', [ProfileController::class, 'stats'])->name('api.profile.stats');

    Route::get('notifications', [NotificationController::class, 'index'])->name('api.notifications.index');
    Route::get('chat/history', [ChatController::class, 'apiHistory'])->name('api.chat.history');

    Route::prefix('admin')->name('api.admin.')->middleware('can:access-admin')->group(function () {
        Route::get('events/stats', [AdminStatsController::class, 'eventStats'])->name('events.stats');
    });
});
