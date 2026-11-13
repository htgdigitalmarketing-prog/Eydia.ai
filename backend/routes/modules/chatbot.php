<?php

use App\Modules\Chatbot\Controllers\ChatbotController;
use Illuminate\Support\Facades\Route;

Route::prefix('chatbots')->group(function () {
    // Free to view/explore (declare /lead-flow before /{uuid}).
    Route::get('/', [ChatbotController::class, 'index']);
    Route::get('/lead-flow', [ChatbotController::class, 'leadFlowStatus']);
    Route::get('/{uuid}', [ChatbotController::class, 'show']);

    // PAID — creating/managing a chatbot needs an active plan.
    Route::middleware('plan')->group(function () {
        Route::post('/', [ChatbotController::class, 'store']);
        Route::post('/lead-flow/install', [ChatbotController::class, 'installGbpFlow']);
        Route::put('/{uuid}', [ChatbotController::class, 'update']);
        Route::delete('/{uuid}', [ChatbotController::class, 'destroy']);
        Route::post('/{uuid}/toggle', [ChatbotController::class, 'toggle']);
        Route::post('/{uuid}/rules', [ChatbotController::class, 'addRule']);
        Route::put('/{uuid}/rules/{rule}', [ChatbotController::class, 'updateRule']);
        Route::delete('/{uuid}/rules/{rule}', [ChatbotController::class, 'deleteRule']);
    });
});
