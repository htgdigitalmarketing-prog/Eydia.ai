<?php

use App\Modules\Templates\Controllers\TemplateController;
use Illuminate\Support\Facades\Route;

Route::prefix('templates')->group(function () {
    // Free to view/explore.
    Route::get('/', [TemplateController::class, 'index']);
    Route::get('/{uuid}', [TemplateController::class, 'show']);

    // PAID — creating/managing templates needs an active plan.
    Route::middleware('plan')->group(function () {
        Route::post('/', [TemplateController::class, 'store']);
        Route::post('/upload-sample', [TemplateController::class, 'uploadSample']);
        Route::post('/sync', [TemplateController::class, 'sync']);
        Route::delete('/{uuid}', [TemplateController::class, 'destroy']);
    });
});
