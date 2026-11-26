<?php

use App\Modules\Records\Enums\RecordType;
use App\Modules\Reports\Forms\FormCatalogue;
use App\Modules\Reports\Http\Controllers\DashboardController;
use App\Modules\Reports\Http\Controllers\ExportController;
use App\Modules\Reports\Http\Controllers\FormController;
use Illuminate\Support\Facades\Route;

// Under /api/v1/staff (ModuleServiceProvider).
Route::middleware(['auth:staff', 'staff.active'])->group(function () {
    // The per-person forms (D-050): whoever reads the files.
    Route::middleware('can:employees.view')->group(function () {
        Route::get('employees/{employee}/forms', [FormController::class, 'index'])->whereUuid('employee');
        Route::get('employees/{employee}/forms/{form}', [FormController::class, 'show'])->whereUuid('employee')->whereIn('form', FormCatalogue::keys());
        Route::get('employees/{employee}/forms/{form}/pdf', [FormController::class, 'pdf'])->whereUuid('employee')->whereIn('form', FormCatalogue::keys());
    });

    // The dashboards and the exports (D-051, D-052): «عرض لوحات القيادة والتقارير وتصديرها».
    Route::middleware('can:reports.view')->group(function () {
        Route::get('reports/overview', [DashboardController::class, 'overview']);
        Route::get('reports/overview/contracts-ending', [DashboardController::class, 'contractsEnding']);
        Route::get('reports/overview/overdue-reviews', [DashboardController::class, 'overdueReviews']);
        Route::get('reports/employees', [DashboardController::class, 'employees']);
        Route::get('reports/movements', [DashboardController::class, 'movements']);
        Route::get('reports/movements/employees', [DashboardController::class, 'movementsByEmployee']);

        Route::get('exports/employees', [ExportController::class, 'employees'])->middleware('can:employees.view');
        Route::get('exports/records/{type}', [ExportController::class, 'records'])->middleware('can:records.view')
            ->whereIn('type', array_map(fn (RecordType $t) => $t->value, RecordType::cases()));
        Route::get('exports/movements-summary', [ExportController::class, 'movementsSummary']);
    });
});
