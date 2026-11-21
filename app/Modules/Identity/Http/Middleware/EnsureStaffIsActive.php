<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Models\StaffUser;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A deactivated user's tokens stop working at once, on every request (D-014). */
class EnsureStaffIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof StaffUser && ! $user->is_active) {
            throw new AuthenticationException;
        }

        return $next($request);
    }
}
