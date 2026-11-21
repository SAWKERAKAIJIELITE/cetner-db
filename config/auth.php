<?php

use App\Modules\Identity\Models\StaffUser;

return [

    /*
    | Staff are the only actor of this system (data entry employees, the general manager, the system
    | administrator). They authenticate with Sanctum bearer tokens issued by the device-bound login.
    | The `web` guard stays only because Sanctum expects one; no route uses it.
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'staff'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'staff_users'),
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'staff_users',
        ],

        'staff' => [
            'driver' => 'sanctum',
            'provider' => 'staff_users',
        ],
    ],

    'providers' => [
        'staff_users' => [
            'driver' => 'eloquent',
            'model' => StaffUser::class,
        ],
    ],

    // No password reset by e-mail: an administrator sets a new password (D-071 of the recruitment system).
    'passwords' => [
        'staff_users' => [
            'provider' => 'staff_users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
