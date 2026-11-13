<?php

use App\Modules\Automation\Controllers\WorkflowController;
use Illuminate\Support\Facades\Route;

Route::prefix('automations')->group(function () {
    // Free to view/explore ("visible but disabled" until a plan is purchased).
    Route::get('/', [WorkflowController::class, 'index']);
    Route::get('/{uuid}', [WorkflowController::class, 'show']);
    Route::get('/{uuid}/executions', [WorkflowController::class, 'executions']);

    // PAID — creating/editing/running automations needs an active plan.
    Route::middleware('plan')->group(function () {
        Route::post('/', [WorkflowController::class, 'store']);
        Route::put('/{uuid}', [WorkflowController::class, 'update']);
        Route::delete('/{uuid}', [WorkflowController::class, 'destroy']);
        Route::put('/{uuid}/canvas', [WorkflowController::class, 'saveCanvas']);
        Route::post('/{uuid}/activate', [WorkflowController::class, 'activate']);
        Route::post('/{uuid}/pause', [WorkflowController::class, 'pause']);
    });
});
