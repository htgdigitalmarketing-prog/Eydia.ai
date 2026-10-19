<?php

use App\Modules\Gbp\Controllers\GbpController;
use App\Modules\Gbp\Controllers\GbpOAuthController;
use App\Modules\Gbp\Controllers\GbpPhotoController;
use App\Modules\Gbp\Controllers\GbpPostController;
use Illuminate\Support\Facades\Route;

// Google Business Profile / Reviews dashboard. Gated by plan feature 'dash_gbp'.
Route::prefix('gbp')->middleware('feature:dash_gbp')->group(function () {
    Route::get('/overview', [GbpController::class, 'overview']);
    Route::get('/reviews', [GbpController::class, 'reviews']);
    Route::post('/reviews/{uuid}/reply', [GbpController::class, 'reply']);
    Route::post('/sync', [GbpController::class, 'sync']);

    // Business insights (performance metrics) + CSV export.
    Route::get('/insights', [GbpController::class, 'insights']);
    Route::get('/insights/export', [GbpController::class, 'insightsCsv']);

    // Google audit / health score.
    Route::get('/health', [GbpController::class, 'health']);

    // GBP posts (localPosts).
    Route::get('/posts', [GbpPostController::class, 'index']);
    Route::post('/posts', [GbpPostController::class, 'store']);
    Route::put('/posts/{uuid}', [GbpPostController::class, 'update']);
    Route::post('/posts/{uuid}/publish', [GbpPostController::class, 'publish']);
    Route::delete('/posts/{uuid}', [GbpPostController::class, 'destroy']);

    // GBP photos (media).
    Route::get('/photos', [GbpPhotoController::class, 'index']);
    Route::post('/photos', [GbpPhotoController::class, 'store']);
    Route::post('/photos/{uuid}/publish', [GbpPhotoController::class, 'publish']);
    Route::delete('/photos/{uuid}', [GbpPhotoController::class, 'destroy']);

    // Connect / inspect / disconnect the tenant's Google Business Profile.
    Route::get('/connect', [GbpOAuthController::class, 'connect']);
    Route::get('/connection', [GbpOAuthController::class, 'status']);
    Route::delete('/connection', [GbpOAuthController::class, 'disconnect']);
});
