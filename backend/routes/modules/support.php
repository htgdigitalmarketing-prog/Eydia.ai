<?php

use App\Modules\Support\Controllers\SupportTicketController;
use Illuminate\Support\Facades\Route;

Route::prefix('support')->group(function () {
    // Super admin: all tickets across tenants (must be before /{uuid})
    Route::get('/admin/all', [SupportTicketController::class, 'adminIndex']);

    Route::get('/', [SupportTicketController::class, 'index']);
    Route::post('/', [SupportTicketController::class, 'store']);
    Route::get('/{uuid}', [SupportTicketController::class, 'show']);
    Route::put('/{uuid}/reply', [SupportTicketController::class, 'reply']);
});
