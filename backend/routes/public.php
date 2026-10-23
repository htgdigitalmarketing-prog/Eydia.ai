<?php

use App\Modules\Gbp\Controllers\PublicSiteController;
use Illuminate\Support\Facades\Route;

// Public, unauthenticated business microsites: eydia.in/site/{slug}
Route::get('/site/{slug}', [PublicSiteController::class, 'show'])
    ->where('slug', '[a-z0-9\-]+');
