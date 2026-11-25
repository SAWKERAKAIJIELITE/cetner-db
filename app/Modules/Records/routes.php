<?php

use App\Modules\Records\Http\Controllers\EmployeeRecordsController;
use App\Modules\Records\Http\Controllers\RecordChangeController;
use App\Modules\Records\Http\Controllers\RecordController;
use Illuminate\Support\Facades\Route;

// Under /api/v1/staff (ModuleServiceProvider). `{type}`: movements, attendance, conduct, debts.
Route::middleware(['auth:staff', 'staff.active'])->group(function () {
    $types = ['movements', 'attendance', 'conduct', 'debts'];

    Route::middleware('can:records.view')->group(function () use ($types) {
        Route::get('records/attendance/planned-days', [EmployeeRecordsController::class, 'plannedDays']);
        Route::get('records/{type}/fields', [RecordController::class, 'fields'])->whereIn('type', $types);
        Route::get('records/{type}', [RecordController::class, 'index'])->whereIn('type', $types);
        Route::get('records/{type}/{record}', [RecordController::class, 'show'])->whereIn('type', $types)->whereUuid('record');
        Route::get('records/{type}/{record}/history', [RecordController::class, 'history'])->whereIn('type', $types)->whereUuid('record');
        Route::get('employees/{employee}/records/summary', [EmployeeRecordsController::class, 'summary'])->whereUuid('employee');
        Route::get('record-changes', [RecordChangeController::class, 'index']);
        Route::get('record-changes/{set}', [RecordChangeController::class, 'show'])->whereUuid('set');
    });

    Route::post('records/{type}', [RecordController::class, 'store'])->whereIn('type', $types)->middleware('can:records.create');
    // Creates need records.create, corrections records.update: checked for each change.
    Route::post('records/{type}/batch', [RecordController::class, 'batch'])->whereIn('type', $types);
    Route::patch('records/{type}/{record}', [RecordController::class, 'update'])->whereIn('type', $types)->whereUuid('record')->middleware('can:records.update');
    Route::delete('records/{type}/{record}', [RecordController::class, 'destroy'])->whereIn('type', $types)->whereUuid('record')->middleware('can:records.delete');

    Route::middleware('can:entries.review')->group(function () {
        Route::post('record-changes/{set}/approve', [RecordChangeController::class, 'approve'])->whereUuid('set');
        Route::post('record-changes/{set}/reject', [RecordChangeController::class, 'reject'])->whereUuid('set');
    });

    Route::post('record-changes/{set}/withdraw', [RecordChangeController::class, 'withdraw'])->whereUuid('set');
});
