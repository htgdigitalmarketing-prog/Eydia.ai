<?php

use App\Modules\Social\Controllers\SocialController;
use Illuminate\Support\Facades\Route;

Route::prefix('social')->group(function () {
    Route::get('/connection', [SocialController::class, 'connection']);
    Route::post('/pages', [SocialController::class, 'pages']);
    Route::post('/connect', [SocialController::class, 'connect']);
    Route::post('/recheck-instagram', [SocialController::class, 'recheckInstagram']);
    Route::delete('/connection', [SocialController::class, 'disconnect']);

    Route::get('/posts', [SocialController::class, 'posts']);
    Route::post('/posts', [SocialController::class, 'createPost']);
    Route::post('/posts/{uuid}/publish', [SocialController::class, 'publish']);
    Route::put('/posts/{uuid}', [SocialController::class, 'updatePost']);
    Route::delete('/posts/{uuid}', [SocialController::class, 'deletePost']);
});
