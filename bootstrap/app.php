<?php

use App\Http\Middleware\UnescapedUnicodeJson;
use App\Modules\Identity\Http\Middleware\EnsureStaffIsActive;
use App\Modules\Identity\Http\Middleware\RequireAnyPermission;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** One JSON error body, Arabic written as UTF-8 (D-005). */
$json = static fn (string $message, int $status, array $headers = []): JsonResponse => new JsonResponse(['message' => $message], $status, $headers, JSON_UNESCAPED_UNICODE);

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(UnescapedUnicodeJson::class);
        $middleware->alias([
            'staff.active' => EnsureStaffIsActive::class,
            'can.any' => RequireAnyPermission::class,
        ]);
        // Bearer tokens only: an unauthenticated API request is a 401, never a redirect to a login page.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions) use ($json): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($json) {
            return $json(__('auth.unauthenticated'), 401);
        });

        // A refusal without a reason of its own gets one Arabic message; one that says why keeps it (D-011).
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) use ($json) {
            $message = trim($e->getMessage());
            $generic = $message === '' || $message === 'This action is unauthorized.' || $message === 'Forbidden';

            return $json($generic ? __('auth.forbidden') : $message, 403);
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($json) {
            return $json(__('auth.throttle_generic'), 429, $e->getHeaders());
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($json) {
            if ($request->is('api/*')) {
                return $json(__('auth.not_found'), 404);
            }

            return null;
        });
    })->create();
