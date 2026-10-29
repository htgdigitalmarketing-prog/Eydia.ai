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
    Route::post('/reviews/{uuid}/suggest', [GbpController::class, 'suggestReply']);
    Route::post('/sync', [GbpController::class, 'sync']);

    // One-click AI optimization of the profile.
    Route::post('/optimize', [GbpController::class, 'optimize']);

    // Hide / restore a synced location (an agency Google account can manage many).
    Route::post('/locations/{uuid}/hidden', [GbpController::class, 'setLocationHidden']);

    // Business Information editor (About / Contact / Location / Hours / More).
    Route::get('/profile', [GbpController::class, 'profile']);
    Route::put('/profile', [GbpController::class, 'updateProfile']);
    // AI: rewrite the business description for local SEO.
    Route::post('/profile/ai-rewrite', [GbpController::class, 'aiRewriteProfile']);

    // Automation control centre (ON/OFF switches + cadence + QR review-assist).
    Route::get('/automation', [\App\Modules\Gbp\Controllers\GbpAutomationController::class, 'show']);
    Route::put('/automation', [\App\Modules\Gbp\Controllers\GbpAutomationController::class, 'update']);

    // Business insights (performance metrics) + CSV export.
    Route::get('/insights', [GbpController::class, 'insights']);
    Route::get('/insights/export', [GbpController::class, 'insightsCsv']);

    // Google audit / health score.
    Route::get('/health', [GbpController::class, 'health']);

    // Image upload for posts & photos (drag & drop / browse).
    Route::post('/upload', [GbpController::class, 'uploadImage']);

    // Instant business website + GBP report generator (public URL).
    Route::get('/site', [GbpController::class, 'siteStatus']);
    Route::post('/site/generate', [GbpController::class, 'generateSite']);

    // GBP posts (localPosts).
    Route::get('/posts', [GbpPostController::class, 'index']);
    Route::post('/posts/generate', [GbpPostController::class, 'generate']); // AI caption
    Route::post('/posts/auto-plan', [GbpPostController::class, 'autoPlan']); // AI batch + schedule
    Route::post('/posts', [GbpPostController::class, 'store']);
    Route::put('/posts/{uuid}', [GbpPostController::class, 'update']);
    Route::post('/posts/{uuid}/publish', [GbpPostController::class, 'publish']);
    Route::delete('/posts/{uuid}', [GbpPostController::class, 'destroy']);

    // GBP photos (media).
    Route::get('/photos', [GbpPhotoController::class, 'index']);
    Route::post('/photos/caption', [GbpPhotoController::class, 'caption']); // AI caption
    Route::post('/photos', [GbpPhotoController::class, 'store']);
    Route::post('/photos/{uuid}/publish', [GbpPhotoController::class, 'publish']);
    Route::delete('/photos/{uuid}', [GbpPhotoController::class, 'destroy']);

    // Connect / inspect / disconnect the tenant's Google Business Profile.
    Route::get('/connect', [GbpOAuthController::class, 'connect']);
    Route::get('/connection', [GbpOAuthController::class, 'status']);
    Route::delete('/connection', [GbpOAuthController::class, 'disconnect']);
});
