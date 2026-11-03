<?php

use App\Modules\Gbp\Controllers\MediaController;
use App\Modules\Gbp\Controllers\PublicSiteController;
use App\Modules\Gbp\Controllers\ReviewAssistController;
use Illuminate\Support\Facades\Route;

// Public media (GBP post images, AI photos) served from the public disk:
// eydia.in/media/gbp/… — works without a web-root storage symlink, and Google
// fetches post images from here.
Route::get('/media/{path}', [MediaController::class, 'show'])
    ->where('path', '[A-Za-z0-9/_\-.]+');

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
