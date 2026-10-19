<?php

use App\Modules\Campaigns\Controllers\CampaignController;
use Illuminate\Support\Facades\Route;

Route::prefix('campaigns')->group(function () {
    Route::get('/', [CampaignController::class, 'index']);
    Route::post('/', [CampaignController::class, 'store']);
    Route::get('/{uuid}', [CampaignController::class, 'show']);
    Route::post('/{uuid}/start', [CampaignController::class, 'start']);
    Route::post('/{uuid}/pause', [CampaignController::class, 'pause']);
    Route::post('/{uuid}/cancel', [CampaignController::class, 'cancel']);
    Route::delete('/{uuid}', [CampaignController::class, 'destroy']);
});
