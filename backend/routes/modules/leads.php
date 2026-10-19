<?php

use App\Modules\Leads\Controllers\LeadController;
use App\Modules\Leads\Controllers\MetaLeadsController;
use Illuminate\Support\Facades\Route;

// Lead Management dashboard. Gated by the plan feature 'dash_leads' via the
// 'feature' middleware so a plan without the Lead dashboard gets Access Denied.
Route::prefix('leads')->middleware('feature:dash_leads')->group(function () {
    Route::get('/', [LeadController::class, 'index']);
    Route::post('/', [LeadController::class, 'store']);
    Route::post('/sync', [LeadController::class, 'sync']);

    // Meta Lead Ads connection (OAuth connect handled by the browser callback).
    Route::get('/meta/connect', [MetaLeadsController::class, 'connect']);
    Route::get('/meta/connection', [MetaLeadsController::class, 'status']);
    Route::delete('/meta/connection', [MetaLeadsController::class, 'disconnect']);

    // Google Lead Form webhook details (delivery URL + key) for this tenant.
    Route::get('/google/connection', [LeadController::class, 'googleConnection']);
    Route::delete('/google/connection', [LeadController::class, 'googleDisconnect']);

    Route::put('/{uuid}', [LeadController::class, 'update']);
    Route::put('/{uuid}/move', [LeadController::class, 'move']);
    Route::delete('/{uuid}', [LeadController::class, 'destroy']);
});
