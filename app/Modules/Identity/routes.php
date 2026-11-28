<?php

use App\Modules\Identity\Http\Controllers\AccessController;
use App\Modules\Identity\Http\Controllers\StaffAuthController;
use App\Modules\Identity\Http\Controllers\StaffDeviceController;
use App\Modules\Identity\Http\Controllers\StaffUserController;
use Illuminate\Support\Facades\Route;

// Under /api/v1/staff (ModuleServiceProvider).

// Public: device enrolment and login (D-013, D-014).
Route::post('devices/register', [StaffDeviceController::class, 'register'])->middleware('throttle:device-register');
Route::get('devices/{device}/status', [StaffDeviceController::class, 'status'])->whereUuid('device')->middleware('throttle:device-status');
Route::post('auth/challenge', [StaffAuthController::class, 'challenge'])->middleware('throttle:staff-challenge');
Route::post('auth/login', [StaffAuthController::class, 'login'])->middleware('throttle:staff-login');

Route::middleware(['auth:staff', 'staff.active'])->group(function () {
    Route::post('auth/logout', [StaffAuthController::class, 'logout']);
    Route::get('auth/me', [StaffAuthController::class, 'me']);

    Route::middleware('can:devices.approve')->group(function () {
        Route::get('devices', [StaffDeviceController::class, 'index']);
        Route::post('devices/{device}/approve', [StaffDeviceController::class, 'approve'])->whereUuid('device');
        Route::post('devices/{device}/revoke', [StaffDeviceController::class, 'revoke'])->whereUuid('device');
    });

    Route::middleware('can:users.manage')->group(function () {
        Route::get('permissions', [AccessController::class, 'permissions']);
        Route::get('roles', [AccessController::class, 'roles']);
        Route::post('roles/{role:slug}/permissions', [AccessController::class, 'addPermissions']);
        Route::delete('roles/{role:slug}/permissions/{permission:slug}', [AccessController::class, 'removePermission']);

        Route::get('users', [StaffUserController::class, 'index']);
        // Adding a staff user: the hiring system's path (D-047).
        Route::post('new', [StaffUserController::class, 'store']);
        Route::get('users/{user}', [StaffUserController::class, 'show'])->whereUuid('user');
        Route::put('users/{user}/role', [StaffUserController::class, 'setRole'])->whereUuid('user');
        Route::post('users/{user}/deactivate', [StaffUserController::class, 'deactivate'])->whereUuid('user');
        Route::post('users/{user}/activate', [StaffUserController::class, 'activate'])->whereUuid('user');
        Route::put('users/{user}/password', [StaffUserController::class, 'resetPassword'])->whereUuid('user');
    });
});
