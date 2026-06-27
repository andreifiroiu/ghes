<?php

declare(strict_types=1);

use App\Http\Controllers\EventController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\RecommendationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('events', [EventController::class, 'apiIndex'])->name('api.events.index');
    Route::get('events/saved', [EventController::class, 'apiSaved'])->name('api.events.saved');
    Route::get('events/{event}', [EventController::class, 'apiShow'])->name('api.events.show');
    Route::get('recommendations', [RecommendationController::class, 'apiIndex'])->name('api.recommendations');
    Route::post('feedback', [FeedbackController::class, 'store'])->name('api.feedback.store');
});
