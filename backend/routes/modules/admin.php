<?php

use App\Modules\Admin\Controllers\CompanyController;
use App\Modules\Admin\Controllers\CreditPackController;
use App\Modules\Admin\Controllers\PlanController;
use App\Modules\Admin\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

// Platform super-admin: manage companies (tenants) and subscription plans.
Route::prefix('admin')->middleware('super_admin')->group(function () {
    Route::get('/companies', [CompanyController::class, 'index']);
    Route::post('/companies', [CompanyController::class, 'store']);
    Route::put('/companies/{uuid}', [CompanyController::class, 'update']);
    Route::post('/companies/{uuid}/toggle', [CompanyController::class, 'toggle']);

    // Platform-wide payment history (all companies).
    Route::get('/payments', [CompanyController::class, 'payments']);
    Route::post('/companies/{uuid}/plan', [CompanyController::class, 'assignPlan']);
    Route::post('/companies/{uuid}/credits', [CompanyController::class, 'addCredits']);
    Route::get('/companies/{uuid}/users', [CompanyController::class, 'users']);
    Route::post('/companies/{uuid}/users/{userUuid}/reset-password', [CompanyController::class, 'resetUserPassword']);
    Route::delete('/companies/{uuid}', [CompanyController::class, 'destroy']);

    Route::get('/plans', [PlanController::class, 'index']);
    Route::post('/plans', [PlanController::class, 'store']);
    Route::put('/plans/{uuid}', [PlanController::class, 'update']);
    Route::post('/plans/{uuid}/toggle', [PlanController::class, 'toggle']);
    Route::delete('/plans/{uuid}', [PlanController::class, 'destroy']);

    // Buyable AI credit packs (platform-wide).
    Route::get('/credit-packs', [CreditPackController::class, 'index']);
    Route::post('/credit-packs', [CreditPackController::class, 'store']);
    Route::put('/credit-packs/{uuid}', [CreditPackController::class, 'update']);
    Route::post('/credit-packs/{uuid}/toggle', [CreditPackController::class, 'toggle']);
    Route::delete('/credit-packs/{uuid}', [CreditPackController::class, 'destroy']);

    Route::get('/settings', [SettingsController::class, 'show']);
    Route::put('/settings', [SettingsController::class, 'update']);
});
