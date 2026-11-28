<?php

use App\Modules\Documents\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

// Under /api/v1/staff (ModuleServiceProvider).
Route::middleware(['auth:staff', 'staff.active'])->group(function () {
    Route::middleware('can:documents.view')->group(function () {
        Route::get('document-kinds', [DocumentController::class, 'kinds']);
        Route::get('employees/{employee}/documents', [DocumentController::class, 'employee'])->whereUuid('employee');
        Route::get('employees/{employee}/documents/history', [DocumentController::class, 'history'])->whereUuid('employee');
        Route::get('documents', [DocumentController::class, 'index']);
        Route::get('documents/{document}', [DocumentController::class, 'show'])->whereUuid('document');
        Route::get('documents/{document}/download', [DocumentController::class, 'download'])->whereUuid('document');
    });

    Route::post('employees/{employee}/documents', [DocumentController::class, 'store'])->whereUuid('employee')->middleware('can:documents.manage');
    // The administrator's: documents.manage here, entries.review in the controller.
    Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->whereUuid('document')->middleware('can:documents.manage');

    Route::middleware('can:entries.review')->group(function () {
        Route::post('documents/{document}/approve', [DocumentController::class, 'approve'])->whereUuid('document');
        Route::post('documents/{document}/reject', [DocumentController::class, 'reject'])->whereUuid('document');
    });

    Route::post('documents/{document}/withdraw', [DocumentController::class, 'withdraw'])->whereUuid('document');
});
