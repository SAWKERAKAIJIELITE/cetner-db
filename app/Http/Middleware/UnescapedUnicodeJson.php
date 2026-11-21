<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes Arabic text in JSON responses as UTF-8, not as \u escapes (D-018 of the recruitment system).
 *
 * It is a global middleware here (not only the api group's), so the responses of the authentication and
 * throttle middleware, which run before the group's own middleware, are written the same way (D-005).
 */
class UnescapedUnicodeJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response instanceof JsonResponse) {
            $response->setEncodingOptions($response->getEncodingOptions() | JSON_UNESCAPED_UNICODE);
        }

        return $response;
    }
}
