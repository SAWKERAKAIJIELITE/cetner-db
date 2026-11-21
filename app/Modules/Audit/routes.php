<?php

use App\Modules\Audit\Http\Controllers\AuditEventController;
use Illuminate\Support\Facades\Route;

// Under /api/v1/staff (ModuleServiceProvider).
Route::middleware(['auth:staff', 'staff.active', 'can.any:audit.view,audit.view_related'])->group(function () {
    Route::get('audit-events', [AuditEventController::class, 'index']);
    Route::get('audit-events/events', [AuditEventController::class, 'events']);
});
