<?php

use App\Modules\Team\Controllers\TeamController;
use Illuminate\Support\Facades\Route;

Route::prefix('team')->group(function () {
    Route::get('/', [TeamController::class, 'index']);
    Route::post('/', [TeamController::class, 'store']);
    Route::put('/{uuid}', [TeamController::class, 'update']);
    Route::post('/{uuid}/toggle', [TeamController::class, 'toggle']);
    Route::delete('/{uuid}', [TeamController::class, 'destroy']);
});
