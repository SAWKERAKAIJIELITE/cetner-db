<?php

use App\Modules\Notifications\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

// Under /api/v1/staff (ModuleServiceProvider): any signed-in active staff user, their own only.
Route::middleware(['auth:staff', 'staff.active'])->group(function () {
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->whereUuid('notification');
    Route::get('badges', [NotificationController::class, 'badges']);
});
