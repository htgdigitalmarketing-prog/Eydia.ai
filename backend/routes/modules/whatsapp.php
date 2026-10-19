<?php

use App\Modules\WhatsApp\Controllers\BulkSendController;
use App\Modules\WhatsApp\Controllers\InboxController;
use App\Modules\WhatsApp\Controllers\WhatsAppAccountController;
use Illuminate\Support\Facades\Route;

/*
| WhatsApp connection module. Mounted inside the ['auth:api','tenant'] group,
| so every action is authenticated and tenant-scoped. Fine-grained permissions
| (whatsapp.view / .connect / .manage) are enforced per-action via Gate.
*/

Route::prefix('whatsapp')->group(function () {
    Route::get('/config', [WhatsAppAccountController::class, 'config']);
    Route::get('/numbers', [WhatsAppAccountController::class, 'numbers']);
    Route::get('/accounts', [WhatsAppAccountController::class, 'accounts']);

    Route::post('/connect-manual', [WhatsAppAccountController::class, 'connectManual']);
    Route::post('/embedded-signup', [WhatsAppAccountController::class, 'embeddedSignup']);

    Route::post('/numbers/{number}/sync', [WhatsAppAccountController::class, 'sync']);
    Route::post('/numbers/{number}/register', [WhatsAppAccountController::class, 'register']);
    Route::post('/numbers/{number}/subscribe-app', [WhatsAppAccountController::class, 'subscribeApp']);
    Route::post('/numbers/{number}/send-test', [WhatsAppAccountController::class, 'sendTest']);
    Route::put('/numbers/{number}/token', [WhatsAppAccountController::class, 'updateToken']);
    Route::delete('/numbers/{number}', [WhatsAppAccountController::class, 'destroy']);

    // Inbox
    Route::get('/conversations', [InboxController::class, 'conversations']);
    Route::get('/conversations/{conversation}/messages', [InboxController::class, 'messages']);
    Route::post('/conversations/{conversation}/mark-read', [InboxController::class, 'markRead']);
    Route::post('/conversations/{conversation}/send', [InboxController::class, 'send']);
    Route::post('/conversations/{conversation}/send-media', [InboxController::class, 'sendMedia']);
    Route::delete('/conversations/{conversation}/messages/{message}', [InboxController::class, 'deleteMessage']);

    // Bulk send
    Route::get('/bulk-sends', [BulkSendController::class, 'index']);
    Route::get('/bulk-sends/{uuid}', [BulkSendController::class, 'show']);
    Route::post('/bulk-sends/{uuid}/retry', [BulkSendController::class, 'retry']);
    Route::post('/bulk-send', [BulkSendController::class, 'store']);
    Route::post('/bulk-send/upload-header', [BulkSendController::class, 'uploadHeader']);
});
