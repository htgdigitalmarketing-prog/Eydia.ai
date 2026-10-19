<?php

use App\Modules\Admin\Controllers\CompanyController;
use App\Modules\Admin\Controllers\PlanController;
use App\Modules\Admin\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

// Platform super-admin: manage companies (tenants) and subscription plans.
Route::prefix('admin')->middleware('super_admin')->group(function () {
    Route::get('/companies', [CompanyController::class, 'index']);
    Route::post('/companies', [CompanyController::class, 'store']);
    Route::post('/companies/{uuid}/toggle', [CompanyController::class, 'toggle']);
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

    Route::get('/settings', [SettingsController::class, 'show']);
    Route::put('/settings', [SettingsController::class, 'update']);
});
