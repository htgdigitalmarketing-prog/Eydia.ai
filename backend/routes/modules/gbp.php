<?php

use App\Modules\Gbp\Controllers\GbpController;
use App\Modules\Gbp\Controllers\GbpOAuthController;
use App\Modules\Gbp\Controllers\GbpPhotoController;
use App\Modules\Gbp\Controllers\GbpPostController;
use Illuminate\Support\Facades\Route;

/*
| Google Business Profile / Reviews dashboard.
|
| FREEMIUM: connecting, syncing and VIEWING the profile/reviews/insights is free
| for any signed-in tenant (no plan required) so clients can explore their data
| before buying. Every PAID action (reply, post, photo, edit, automation, AI,
| optimize) sits behind 'feature:dash_gbp' — which also 403s tenants without a
| plan, so it doubles as the paywall (frontend turns that into an upgrade prompt).
*/
Route::prefix('gbp')->group(function () {
    // ── FREE: connect / inspect / disconnect ────────────────────────────────
    Route::get('/connect', [GbpOAuthController::class, 'connect']);
    Route::get('/connection', [GbpOAuthController::class, 'status']);
    Route::delete('/connection', [GbpOAuthController::class, 'disconnect']);

    // ── FREE: view + sync business data ─────────────────────────────────────
    Route::get('/overview', [GbpController::class, 'overview']);
    Route::get('/locations', [GbpController::class, 'locations']);
    Route::get('/reviews', [GbpController::class, 'reviews']);
    Route::post('/sync', [GbpController::class, 'sync']);
    Route::get('/profile', [GbpController::class, 'profile']);
    Route::get('/insights', [GbpController::class, 'insights']);
    Route::get('/insights/export', [GbpController::class, 'insightsCsv']);
    Route::get('/health', [GbpController::class, 'health']);
    Route::get('/site', [GbpController::class, 'siteStatus']);
    Route::get('/posts', [GbpPostController::class, 'index']);
    Route::get('/photos', [GbpPhotoController::class, 'index']);
    // Automation control centre is VIEWABLE for free ("visible but disabled");
    // toggling it on / running it is paid (below).
    Route::get('/automation', [\App\Modules\Gbp\Controllers\GbpAutomationController::class, 'show']);

    // ── PAID: every write / AI / publish action needs the GBP plan feature ───
    Route::middleware('feature:dash_gbp')->group(function () {
        // Reviews.
        Route::post('/reviews/{uuid}/reply', [GbpController::class, 'reply']);
        Route::post('/reviews/{uuid}/suggest', [GbpController::class, 'suggestReply']);

        // One-click AI optimization of the profile.
        Route::post('/optimize', [GbpController::class, 'optimize']);

        // Location management.
        Route::post('/locations/{uuid}/hidden', [GbpController::class, 'setLocationHidden']);
        Route::post('/locations/{uuid}/only', [GbpController::class, 'keepOnlyLocation']);
        Route::delete('/locations/{uuid}', [GbpController::class, 'destroyLocation']);

        // Business Information editor.
        Route::put('/profile', [GbpController::class, 'updateProfile']);
        Route::post('/profile/ai-rewrite', [GbpController::class, 'aiRewriteProfile']);
        Route::post('/profile/auto-setup', [GbpController::class, 'autoSetupProfile']);

        // Automation control (enable / cadence / run now).
        Route::put('/automation', [\App\Modules\Gbp\Controllers\GbpAutomationController::class, 'update']);
        Route::post('/automation/run', [\App\Modules\Gbp\Controllers\GbpAutomationController::class, 'runNow']);

        // Image upload for posts & photos.
        Route::post('/upload', [GbpController::class, 'uploadImage']);

        // Instant business website generator.
        Route::post('/site/generate', [GbpController::class, 'generateSite']);

        // GBP posts (localPosts).
        Route::post('/posts/generate', [GbpPostController::class, 'generate']); // AI caption
        Route::post('/posts/auto-plan', [GbpPostController::class, 'autoPlan']); // AI batch + schedule
        Route::post('/posts', [GbpPostController::class, 'store']);
        Route::put('/posts/{uuid}', [GbpPostController::class, 'update']);
        Route::post('/posts/{uuid}/publish', [GbpPostController::class, 'publish']);
        Route::delete('/posts/{uuid}', [GbpPostController::class, 'destroy']);

        // GBP photos (media).
        Route::post('/photos/caption', [GbpPhotoController::class, 'caption']); // AI caption
        Route::post('/photos', [GbpPhotoController::class, 'store']);
        Route::post('/photos/{uuid}/publish', [GbpPhotoController::class, 'publish']);
        Route::delete('/photos/{uuid}', [GbpPhotoController::class, 'destroy']);
    });
});
