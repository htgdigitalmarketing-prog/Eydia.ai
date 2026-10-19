<?php

use App\Modules\Ads\Controllers\AdController;
use Illuminate\Support\Facades\Route;

// Ads reporting dashboard. Gated by the plan feature 'ads'.
Route::prefix('ads')->middleware('feature:ads')->group(function () {
    Route::get('/', [AdController::class, 'index']);
    Route::post('/', [AdController::class, 'store']);
    Route::delete('/{uuid}', [AdController::class, 'destroy']);
});
