<?php

use App\Modules\Campaigns\Controllers\CampaignController;
use Illuminate\Support\Facades\Route;

Route::prefix('campaigns')->group(function () {
    // Free to view/explore.
    Route::get('/', [CampaignController::class, 'index']);
    Route::get('/{uuid}', [CampaignController::class, 'show']);

    // PAID — creating/running campaigns needs an active plan.
    Route::middleware('plan')->group(function () {
        Route::post('/', [CampaignController::class, 'store']);
        Route::post('/{uuid}/start', [CampaignController::class, 'start']);
        Route::post('/{uuid}/pause', [CampaignController::class, 'pause']);
        Route::post('/{uuid}/cancel', [CampaignController::class, 'cancel']);
        Route::delete('/{uuid}', [CampaignController::class, 'destroy']);
    });
});
