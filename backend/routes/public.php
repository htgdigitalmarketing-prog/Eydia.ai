<?php

use App\Modules\Gbp\Controllers\PublicSiteController;
use App\Modules\Gbp\Controllers\ReviewAssistController;
use Illuminate\Support\Facades\Route;

// Public, unauthenticated business microsites: eydia.in/site/{slug}
Route::get('/site/{slug}', [PublicSiteController::class, 'show'])
    ->where('slug', '[a-z0-9\-]+');

// Public AI review-assist page reached by scanning a business QR code:
// eydia.in/review/{slug}
Route::get('/review/{slug}', [ReviewAssistController::class, 'show'])
    ->where('slug', '[a-z0-9\-]+');
Route::post('/review/{slug}/suggest', [ReviewAssistController::class, 'suggest'])
    ->where('slug', '[a-z0-9\-]+')
    ->middleware('throttle:20,1');
