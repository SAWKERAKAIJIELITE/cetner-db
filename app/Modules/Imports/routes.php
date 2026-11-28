<?php

use App\Modules\Imports\Http\Controllers\ImportController;
use Illuminate\Support\Facades\Route;

// Under /api/v1/staff (ModuleServiceProvider).
Route::middleware(['auth:staff', 'staff.active'])->group(function () {
    // Who imports and who reviews imports see them.
    Route::middleware('can.any:imports.manage,entries.review')->group(function () {
        Route::get('imports', [ImportController::class, 'index']);
        Route::get('imports/{import}', [ImportController::class, 'show'])->whereUuid('import');
        Route::get('imports/{import}/rows', [ImportController::class, 'rows'])->whereUuid('import');
    });

    Route::middleware('can:imports.manage')->group(function () {
        Route::post('imports', [ImportController::class, 'store']);
        Route::post('imports/{import}/cancel', [ImportController::class, 'cancel'])->whereUuid('import');
    });

    Route::middleware('can:entries.review')->group(function () {
        Route::post('imports/{import}/approve', [ImportController::class, 'approve'])->whereUuid('import');
        Route::post('imports/{import}/reject', [ImportController::class, 'reject'])->whereUuid('import');
    });
});
