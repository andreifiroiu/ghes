<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ScraperController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\EmailReactionController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\NotificationSettingsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecommendationController;
use Illuminate\Support\Facades\Route;

// Public landing page — guests see the landing, authenticated users are redirected to dashboard
Route::get('/', [LandingController::class, 'index'])->name('home');

// Signed email reaction URL — no auth required, signature validates identity
Route::get('reactions/{user}/{event}/{reaction}', [EmailReactionController::class, 'store'])
    ->name('reactions.email')
    ->middleware('signed');

// Auth (guest only)
Route::middleware('guest')->group(function () {
    Route::get('register', [RegisterController::class, 'create'])->name('register');
    Route::post('register', [RegisterController::class, 'store'])->middleware('throttle:10,1');
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:5,1');
});

// Authenticated routes
Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    // Onboarding chat
    Route::get('onboarding', [ChatController::class, 'index'])->name('onboarding');
    Route::post('onboarding/chat', [ChatController::class, 'store'])->name('onboarding.chat')->middleware('throttle:20,1');
    Route::post('onboarding/confirm-profile', [ChatController::class, 'confirmProfile'])->name('onboarding.confirm');

    // Dashboard / Recommendations
    Route::get('dashboard', [RecommendationController::class, 'index'])->name('dashboard');

    // Events
    Route::get('events', [EventController::class, 'index'])->name('events.index');
    Route::get('events/saved', [EventController::class, 'saved'])->name('events.saved');
    Route::get('events/{event}', [EventController::class, 'show'])->name('events.show');

    // Feedback (JSON response)
    Route::post('feedback', [FeedbackController::class, 'store'])->name('feedback.store');

    // Profile
    Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('profile/resend-verification', [ProfileController::class, 'resendVerification'])->name('profile.resend-verification');

    // Ongoing profile-update chat
    Route::get('profile/chat', [ChatController::class, 'profileChat'])->name('profile.chat');
    Route::post('profile/chat', [ChatController::class, 'profileChatStore'])->name('profile.chat.store')->middleware('throttle:20,1');
    Route::post('profile/chat/apply', [ChatController::class, 'applyProfileUpdate'])->name('profile.chat.apply');

    // Notification Settings
    Route::get('settings/notifications', [NotificationSettingsController::class, 'show'])->name('settings.notifications');
    Route::put('settings/notifications', [NotificationSettingsController::class, 'update'])->name('settings.notifications.update');

    // Admin
    Route::prefix('admin')->name('admin.')->middleware('can:access-admin')->group(function () {
        Route::get('scrapers', [ScraperController::class, 'index'])->name('scrapers.index');
        Route::post('scrapers/run', [ScraperController::class, 'store'])->name('scrapers.run');
    });
});
