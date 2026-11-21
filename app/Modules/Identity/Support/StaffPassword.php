<?php

namespace App\Modules\Identity\Support;

use Illuminate\Validation\Rules\Password;

/** A staff password: 8+ characters, upper and lower case, a digit, a symbol (D-008). */
final class StaffPassword
{
    public static function rule(): Password
    {
        // Checking against known leaks (`uncompromised()`) needs the internet; the server has none (OD-4).
        return Password::min(8)->letters()->mixedCase()->numbers()->symbols();
    }
}
