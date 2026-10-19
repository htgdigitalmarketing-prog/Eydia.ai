<?php

use App\Modules\Invoicing\Controllers\InvoicingController;
use Illuminate\Support\Facades\Route;

// Customer-invoicing tool (invoices, customers, services, expenses, profile).
// Gated by the plan feature 'invoicing'.
Route::prefix('invoicing')->middleware('feature:invoicing')->group(function () {
    // Invoices
    Route::get('/invoices', [InvoicingController::class, 'invoices']);
    Route::post('/invoices', [InvoicingController::class, 'createInvoice']);
    Route::get('/invoices/{uuid}', [InvoicingController::class, 'showInvoice']);
    Route::post('/invoices/{uuid}/sent', [InvoicingController::class, 'markSent']);
    Route::post('/invoices/{uuid}/paid', [InvoicingController::class, 'markPaid']);
    Route::delete('/invoices/{uuid}', [InvoicingController::class, 'destroyInvoice']);

    // Customers
    Route::get('/customers', [InvoicingController::class, 'customers']);
    Route::post('/customers', [InvoicingController::class, 'storeCustomer']);
    Route::put('/customers/{uuid}', [InvoicingController::class, 'updateCustomer']);
    Route::delete('/customers/{uuid}', [InvoicingController::class, 'destroyCustomer']);

    // Services + categories
    Route::get('/services', [InvoicingController::class, 'services']);
    Route::post('/services', [InvoicingController::class, 'storeService']);
    Route::put('/services/{uuid}', [InvoicingController::class, 'updateService']);
    Route::delete('/services/{uuid}', [InvoicingController::class, 'destroyService']);
    Route::get('/categories', [InvoicingController::class, 'categories']);
    Route::post('/categories', [InvoicingController::class, 'storeCategory']);
    Route::delete('/categories/{uuid}', [InvoicingController::class, 'destroyCategory']);

    // Expenses
    Route::get('/expenses', [InvoicingController::class, 'expenses']);
    Route::post('/expenses', [InvoicingController::class, 'storeExpense']);
    Route::delete('/expenses/{uuid}', [InvoicingController::class, 'destroyExpense']);

    // Billing profile
    Route::get('/profile', [InvoicingController::class, 'profile']);
    Route::put('/profile', [InvoicingController::class, 'updateProfile']);
});
