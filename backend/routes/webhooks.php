<?php

use App\Modules\Billing\Controllers\RazorpayWebhookController;
use App\Modules\Leads\Controllers\LeadWebhookController;
use App\Modules\WhatsApp\Controllers\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Public webhook routes — NO auth middleware, but signature-verified inside
| the controller. GET verifies Meta's hub.challenge; POST receives events,
| stores raw, returns 200 immediately, and queues processing.
*/

Route::prefix('webhooks')->group(function () {
    Route::get('/whatsapp', [WhatsAppWebhookController::class, 'verify']);
    Route::post('/whatsapp', [WhatsAppWebhookController::class, 'receive']);
    Route::post('/razorpay', [RazorpayWebhookController::class, 'handle']);

    // Meta Lead Ads leadgen webhook (GET verifies, POST delivers new leads).
    Route::get('/leads/meta', [LeadWebhookController::class, 'metaVerify']);
    Route::post('/leads/meta', [LeadWebhookController::class, 'metaReceive']);

    // Google Lead Form delivery (key-guarded inside the controller).
    Route::post('/leads/google', [LeadWebhookController::class, 'googleReceive']);
});
