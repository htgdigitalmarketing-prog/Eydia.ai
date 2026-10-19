<?php

use App\Modules\Automation\Controllers\WorkflowController;
use Illuminate\Support\Facades\Route;

Route::prefix('automations')->group(function () {
    Route::get('/', [WorkflowController::class, 'index']);
    Route::post('/', [WorkflowController::class, 'store']);
    Route::get('/{uuid}', [WorkflowController::class, 'show']);
    Route::put('/{uuid}', [WorkflowController::class, 'update']);
    Route::delete('/{uuid}', [WorkflowController::class, 'destroy']);
    Route::put('/{uuid}/canvas', [WorkflowController::class, 'saveCanvas']);
    Route::post('/{uuid}/activate', [WorkflowController::class, 'activate']);
    Route::post('/{uuid}/pause', [WorkflowController::class, 'pause']);
    Route::get('/{uuid}/executions', [WorkflowController::class, 'executions']);
});
