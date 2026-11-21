<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Models\StaffUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** `can.any:a,b`: the user holds at least one of the permissions. */
class RequireAnyPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user instanceof StaffUser || ! collect($permissions)->contains(fn (string $p) => $user->hasPermission($p))) {
            throw new AccessDeniedHttpException;
        }

        return $next($request);
    }
}
