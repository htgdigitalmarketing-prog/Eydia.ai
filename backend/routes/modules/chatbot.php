<?php

use App\Modules\Chatbot\Controllers\ChatbotController;
use Illuminate\Support\Facades\Route;

Route::prefix('chatbots')->group(function () {
    Route::get('/', [ChatbotController::class, 'index']);
    Route::post('/', [ChatbotController::class, 'store']);
    Route::get('/{uuid}', [ChatbotController::class, 'show']);
    Route::put('/{uuid}', [ChatbotController::class, 'update']);
    Route::delete('/{uuid}', [ChatbotController::class, 'destroy']);
    Route::post('/{uuid}/toggle', [ChatbotController::class, 'toggle']);
    Route::post('/{uuid}/rules', [ChatbotController::class, 'addRule']);
    Route::put('/{uuid}/rules/{rule}', [ChatbotController::class, 'updateRule']);
    Route::delete('/{uuid}/rules/{rule}', [ChatbotController::class, 'deleteRule']);
});
