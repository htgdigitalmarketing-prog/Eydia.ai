<?php

use App\Modules\Assistant\Controllers\AssistantController;
use Illuminate\Support\Facades\Route;

// Dashboard AI assistant. Tighter throttle because each call hits an AI provider.
Route::prefix('assistant')->middleware('throttle:20,1')->group(function () {
    Route::post('/ask', [AssistantController::class, 'ask']);
    // Open-ended marketing advisor chat (AI Chat Mode).
    Route::post('/chat', [AssistantController::class, 'chat']);
});
