<?php

use App\Modules\Auth\Controllers\AuthController;
use App\Modules\Auth\Controllers\GoogleAuthController;
use App\Modules\Gbp\Controllers\GbpOAuthController;
use App\Modules\Leads\Controllers\MetaLeadsController;
use Illuminate\Support\Facades\Route;

/*
| Auth module routes. Mounted under /api/v1/auth by routes/api.php.
| Public endpoints are rate-limited; the rest sit behind auth:api.
*/

Route::prefix('auth')->group(function () {
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('/reset-password', [AuthController::class, 'resetPassword']);
        // Mobile-OTP login (alternative to email + password).
        Route::post('/otp/request', [AuthController::class, 'requestOtp']);
        Route::post('/otp/verify', [AuthController::class, 'verifyOtp']);
    });

    // Google OAuth (browser redirect flow — public, no auth:api).
    Route::get('/google/redirect', [GoogleAuthController::class, 'redirect']);
    Route::get('/google/callback', [GoogleAuthController::class, 'callback']);

    // Google Business Profile "connect account" callback (public browser
    // redirect; the tenant is carried in the signed state, not the session).
    Route::get('/gbp/callback', [GbpOAuthController::class, 'callback']);

    // Meta Lead Ads "connect" callback (public browser redirect).
    Route::get('/meta-leads/callback', [MetaLeadsController::class, 'callback']);

    Route::middleware('auth:api')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::put('/profile', [AuthController::class, 'updateProfile']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
    });
});
