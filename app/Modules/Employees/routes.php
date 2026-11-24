<?php

use App\Modules\Employees\Http\Controllers\ChangeSetController;
use App\Modules\Employees\Http\Controllers\EmployeeController;
use App\Modules\Employees\Http\Controllers\FieldDefinitionController;
use Illuminate\Support\Facades\Route;

// Under /api/v1/staff (ModuleServiceProvider).
Route::middleware(['auth:staff', 'staff.active'])->group(function () {
    Route::get('field-definitions', [FieldDefinitionController::class, 'index']);
    Route::put('field-definitions/{definition}', [FieldDefinitionController::class, 'update'])->middleware('can:settings.manage');

    Route::middleware('can:employees.view')->group(function () {
        Route::get('employees', [EmployeeController::class, 'index']);
        Route::get('employees/{employee}', [EmployeeController::class, 'show'])->whereUuid('employee');
        Route::get('employees/{employee}/reviews', [EmployeeController::class, 'reviews'])->whereUuid('employee');
        Route::get('employees/{employee}/history', [EmployeeController::class, 'history'])->whereUuid('employee');
        Route::get('employee-changes', [ChangeSetController::class, 'index']);
        Route::get('employee-changes/{set}', [ChangeSetController::class, 'show'])->whereUuid('set');
    });

    Route::post('employees', [EmployeeController::class, 'store'])->middleware('can:employees.create');

    Route::middleware('can:employees.update')->group(function () {
        Route::patch('employees/{employee}', [EmployeeController::class, 'update'])->whereUuid('employee');
        Route::post('employees/{employee}/reviews', [EmployeeController::class, 'review'])->whereUuid('employee');
    });

    Route::post('employees/{employee}/identity/lock', [EmployeeController::class, 'lockIdentity'])->whereUuid('employee')->middleware('can:identity.review');

    Route::middleware('can:entries.review')->group(function () {
        Route::post('employee-changes/{set}/approve', [ChangeSetController::class, 'approve'])->whereUuid('set');
        Route::post('employee-changes/{set}/reject', [ChangeSetController::class, 'reject'])->whereUuid('set');
    });

    Route::post('employee-changes/{set}/withdraw', [ChangeSetController::class, 'withdraw'])->whereUuid('set');
});
