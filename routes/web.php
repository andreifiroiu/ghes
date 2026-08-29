<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\EventController as AdminEventController;
use App\Http\Controllers\Admin\ScraperController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\OAuthController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\BookmarkController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\EmailReactionController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\NotificationSettingsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\RecommendationController;
use Illuminate\Support\Facades\Route;

// Public landing page — guests see the landing, authenticated users are redirected to dashboard
Route::get('/', [LandingController::class, 'index'])->name('home');

// Signed email reaction URL — no auth required, signature validates identity.
// GET only renders a confirmation page; the POST on the same URI does the write,
// so mail scanners and link prefetchers cannot register reactions nobody clicked.
Route::get('reactions/{user}/{event}/{reaction}', [EmailReactionController::class, 'show'])
    ->name('reactions.email')
    ->middleware('signed');
Route::post('reactions/{user}/{event}/{reaction}', [EmailReactionController::class, 'store'])
    ->name('reactions.email.confirm')
    ->middleware('signed');

// Auth (guest only)
Route::middleware('guest')->group(function () {
    Route::get('register', [RegisterController::class, 'create'])->name('register');
    Route::post('register', [RegisterController::class, 'store'])->middleware('throttle:10,1');
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:5,1');

    // OAuth (Google)
    Route::get('auth/{provider}/redirect', [OAuthController::class, 'redirect'])->name('oauth.redirect');
    Route::get('auth/{provider}/callback', [OAuthController::class, 'callback'])->name('oauth.callback');
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
    Route::get('events/saved', [BookmarkController::class, 'index'])->name('events.saved');
    Route::get('events/{event}', [EventController::class, 'show'])->name('events.show');

    // Feedback (JSON response)
    Route::post('feedback', [FeedbackController::class, 'store'])->name('feedback.store');
    Route::delete('feedback', [FeedbackController::class, 'destroy'])->name('feedback.destroy');
    Route::post('bookmarks', [BookmarkController::class, 'store'])->name('bookmarks.store');
    Route::delete('bookmarks', [BookmarkController::class, 'destroy'])->name('bookmarks.destroy');

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

    // Web push subscriptions
    Route::post('push/subscribe', [PushSubscriptionController::class, 'store'])->name('push.subscribe');
    Route::delete('push/subscribe', [PushSubscriptionController::class, 'destroy'])->name('push.unsubscribe');

    // Admin
    Route::prefix('admin')->name('admin.')->middleware('can:access-admin')->group(function () {
        Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');

        // Events
        Route::get('events', [AdminEventController::class, 'index'])->name('events.index');
        Route::get('events/{event}/edit', [AdminEventController::class, 'edit'])->name('events.edit');
        Route::put('events/{event}', [AdminEventController::class, 'update'])->name('events.update');
        Route::delete('events/{event}', [AdminEventController::class, 'destroy'])->name('events.destroy');
        Route::post('events/{event}/hide', [AdminEventController::class, 'toggleHidden'])->name('events.hide');
        Route::post('events/{event}/feature', [AdminEventController::class, 'feature'])->name('events.feature');
        Route::post('events/{event}/reprocess', [AdminEventController::class, 'reprocess'])->name('events.reprocess');

        // Users
        Route::get('users', [AdminUserController::class, 'index'])->name('users.index');
        Route::get('users/{user}', [AdminUserController::class, 'show'])->name('users.show');
        Route::put('users/{user}', [AdminUserController::class, 'update'])->name('users.update');
        Route::delete('users/{user}', [AdminUserController::class, 'destroy'])->name('users.destroy');

        // Scrapers
        Route::get('scrapers', [ScraperController::class, 'index'])->name('scrapers.index');
        Route::post('scrapers/run', [ScraperController::class, 'store'])->name('scrapers.run');
    });
});
