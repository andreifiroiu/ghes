<?php

declare(strict_types=1);

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\Admin\AnalyticsController as AdminAnalyticsController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\DuplicateEventController;
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

// Outbound click tracking. Public and unauthenticated because digest links and
// guest browsing both come through here. The destination is always the event's
// own stored source_url — never a URL from the request — so this cannot be
// turned into an open redirect.
// Throttled: this is an unauthenticated GET that writes a row and feeds a
// ranking signal, so without a limit a loop of curls could push any event's
// engagement_score to the ceiling for every user at the cost of a few requests.
Route::get('go/{event}', [ActivityController::class, 'redirect'])
    ->whereUuid('event')
    ->middleware('throttle:30,1')
    ->name('events.go');

// Digest open pixel. Signed so the open timestamp cannot be forged by anyone
// who gets hold of a forwarded email.
Route::get('e/o/{notification}.gif', [ActivityController::class, 'open'])
    ->whereUuid('notification')
    ->name('notifications.open')
    ->middleware(['signed', 'throttle:60,1']);

// Public, read-only event browsing. The landing page sends guests here, so they
// can see real events before signing up; reacting and saving stay authenticated.
// `{event}` is constrained to a UUID so this cannot shadow `events/saved`,
// which is declared later inside the auth group.
Route::get('events', [EventController::class, 'index'])->name('events.index');
Route::get('events/{event}', [EventController::class, 'show'])
    ->whereUuid('event')
    ->middleware('throttle:120,1')
    ->name('events.show');
// Throttled like the other public tracking routes: it is an unauthenticated
// GET that now writes a row and feeds a ranking signal.
Route::get('events/{event}/calendar.ics', [EventController::class, 'calendar'])
    ->middleware('throttle:30,1')
    ->whereUuid('event')
    ->name('events.calendar');

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

    // Events (browsing is public — see above; saved events are per-user)
    Route::get('events/saved', [BookmarkController::class, 'index'])->name('events.saved');

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
        Route::get('analytics', [AdminAnalyticsController::class, 'index'])->name('analytics');

        // Events
        Route::get('events', [AdminEventController::class, 'index'])->name('events.index');

        // Duplicate review — declared before the {event} routes so the literal
        // segment is not swallowed by the binding.
        Route::get('events/duplicates', [DuplicateEventController::class, 'index'])->name('events.duplicates');
        Route::post('events/merge', [DuplicateEventController::class, 'store'])->name('events.merge');

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
