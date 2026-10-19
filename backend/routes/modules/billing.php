<?php

use App\Modules\Billing\Controllers\BillingController;
use App\Modules\Billing\Controllers\CreditController;
use Illuminate\Support\Facades\Route;

// AI credit balance + usage ledger (reachable without an active plan).
Route::get('/credits', [CreditController::class, 'index']);

Route::prefix('billing')->group(function () {
    Route::get('/', [BillingController::class, 'overview']);
    Route::get('/plans', [BillingController::class, 'plans']);
    Route::get('/wallet', [BillingController::class, 'wallet']);
    Route::get('/invoices', [BillingController::class, 'invoices']);
    Route::post('/subscribe', [BillingController::class, 'subscribe']);
    Route::post('/order', [BillingController::class, 'createOrder']);
    Route::post('/verify', [BillingController::class, 'verifyPayment']);
});
