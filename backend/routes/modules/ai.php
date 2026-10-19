<?php

use App\Modules\AiMedia\Controllers\AiMediaController;
use Illuminate\Support\Facades\Route;

// AI Media generation (text-to-image). Tighter throttle — each generate call
// hits the image model.
Route::prefix('ai-media')->middleware('throttle:30,1')->group(function () {
    Route::get('/', [AiMediaController::class, 'index']);
    Route::post('/', [AiMediaController::class, 'generate']);
    Route::delete('/{uuid}', [AiMediaController::class, 'destroy']);
});
