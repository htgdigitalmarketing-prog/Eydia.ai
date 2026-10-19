<?php

use App\Modules\Agents\Controllers\AgentController;
use Illuminate\Support\Facades\Route;

Route::prefix('agents')->group(function () {
    Route::get('/', [AgentController::class, 'index']);
    Route::get('/assignable', [AgentController::class, 'assignable']);
    Route::get('/stats', [AgentController::class, 'stats']);
    Route::post('/assign', [AgentController::class, 'assign']);
    Route::post('/unassign', [AgentController::class, 'unassign']);
    Route::get('/{uuid}', [AgentController::class, 'show']);
});
