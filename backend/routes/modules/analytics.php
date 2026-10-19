<?php

use App\Modules\Analytics\Controllers\AnalyticsController;
use Illuminate\Support\Facades\Route;

Route::prefix('analytics')->group(function () {
    Route::get('/dashboard', [AnalyticsController::class, 'dashboard']);
    Route::get('/messages', [AnalyticsController::class, 'messageStats']);
    Route::get('/campaigns', [AnalyticsController::class, 'campaignStats']);
});
