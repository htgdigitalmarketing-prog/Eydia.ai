<?php

use App\Modules\Mobile\Controllers\DeviceController;
use Illuminate\Support\Facades\Route;

// Mobile push-notification device registration. Outside the plan gate so the
// app can register/unregister regardless of subscription state.
Route::prefix('devices')->group(function () {
    Route::get('/', [DeviceController::class, 'index']);
    Route::post('/', [DeviceController::class, 'register']);
    Route::delete('/', [DeviceController::class, 'unregister']);
});
