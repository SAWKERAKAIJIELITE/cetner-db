<?php

use App\Modules\Settings\Http\Controllers\HolidayController;
use App\Modules\Settings\Http\Controllers\LookupListController;
use App\Modules\Settings\Http\Controllers\OrgUnitController;
use App\Modules\Settings\Http\Controllers\WorkSettingsController;
use Illuminate\Support\Facades\Route;

// Under /api/v1/staff (ModuleServiceProvider). Every signed-in user reads the settings (the forms need them);
// the administrator and the supervisor change them (settings.manage, OD-2).
Route::prefix('settings')->middleware(['auth:staff', 'staff.active'])->group(function () {
    Route::get('lists', [LookupListController::class, 'index']);
    Route::get('lists/{list}', [LookupListController::class, 'show']);
    Route::get('org-units', [OrgUnitController::class, 'index']);
    Route::get('holidays', [HolidayController::class, 'index']);
    Route::get('work', [WorkSettingsController::class, 'show']);

    Route::middleware('can:settings.manage')->group(function () {
        Route::post('lists/{list}/items', [LookupListController::class, 'store']);
        Route::put('lists/{list}/items/{item}', [LookupListController::class, 'update'])->whereNumber('item');
        Route::post('lists/{list}/items/{item}/archive', [LookupListController::class, 'archive'])->whereNumber('item');
        Route::post('lists/{list}/items/{item}/restore', [LookupListController::class, 'restore'])->whereNumber('item');
        Route::put('lists/{list}/order', [LookupListController::class, 'reorder']);

        Route::post('org-units', [OrgUnitController::class, 'store']);
        Route::put('org-units/{unit}', [OrgUnitController::class, 'update'])->whereNumber('unit');
        Route::post('org-units/{unit}/archive', [OrgUnitController::class, 'archive'])->whereNumber('unit');
        Route::post('org-units/{unit}/restore', [OrgUnitController::class, 'restore'])->whereNumber('unit');

        Route::post('holidays', [HolidayController::class, 'store']);
        Route::put('holidays/{holiday}', [HolidayController::class, 'update'])->whereNumber('holiday');
        Route::delete('holidays/{holiday}', [HolidayController::class, 'destroy'])->whereNumber('holiday');

        Route::put('work', [WorkSettingsController::class, 'update']);
    });
});
