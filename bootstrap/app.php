<?php

declare(strict_types=1);

use App\Exceptions\ApiException;
use App\Http\Middleware\AuthenticateDevice;
use App\Http\Middleware\BindTenantForUser;
use App\Http\Middleware\EnsureTwoFactorSatisfied;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        // Infrastructure liveness. The device's own reachability probe is the
        // authenticated /api/v1/health, which does considerably more.
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Cookie-based Sanctum auth for the dashboard SPA.
        $middleware->statefulApi();

        // The container sits behind the host's Apache reverse proxy, which is
        // the only public entrance, so its forwarding headers are trusted.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'device' => AuthenticateDevice::class,
            'tenant' => BindTenantForUser::class,
            'two-factor' => EnsureTwoFactorSatisfied::class,
        ]);

        /*
         * Ordering here is load-bearing, not tidiness.
         *
         * Route-model binding runs inside SubstituteBindings. If the tenant is
         * not bound before that, the global scope is not yet active and a
         * binding resolves rows from *any* tenant — so `/devices/{uuid}` would
         * happily fetch someone else's door and only the policy would stop it.
         * Both tenant-binding middlewares therefore run first.
         *
         * The device one runs before ThrottleRequests as well, because the
         * per-device rate limiter keys on the authenticated device; without
         * this it would fall back to keying on the IP, and a whole site's
         * panels commonly share one.
         */
        $middleware->prependToPriorityList(
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
            AuthenticateDevice::class,
        );

        $middleware->prependToPriorityList(
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            BindTenantForUser::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * Every device-facing error uses one envelope:
         *
         *     { "error": { "code": "otp_expired", "message": "..." } }
         *
         * The firmware branches on `code`; `message` is for whoever reads the
         * logs afterwards.
         */
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            // ApiException renders itself; leaving it alone keeps its context
            // fields (attempts_left, retry_after) intact.
            if ($e instanceof ApiException) {
                return null;
            }

            [$code, $status, $message] = match (true) {
                $e instanceof ValidationException => [
                    'validation_failed',
                    422,
                    $e->validator->errors()->first(),
                ],
                $e instanceof AuthenticationException => [
                    'unauthenticated',
                    401,
                    'Authentication is required.',
                ],
                $e instanceof AuthorizationException => [
                    'forbidden',
                    403,
                    'You are not permitted to do that.',
                ],
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => [
                    'not_found',
                    404,
                    'The requested resource does not exist.',
                ],
                // Laravel wraps a failed policy check in an
                // AccessDeniedHttpException before it reaches here, so 403 is
                // matched on the status rather than only on the class.
                $e instanceof HttpExceptionInterface => [
                    match ($e->getStatusCode()) {
                        403 => 'forbidden',
                        404 => 'not_found',
                        405 => 'method_not_allowed',
                        429 => 'rate_limited',
                        default => 'http_error',
                    },
                    $e->getStatusCode(),
                    $e->getMessage() ?: 'Request failed.',
                ],
                default => [
                    'server_error',
                    500,
                    // Never leak internals to a panel on a wall. The real
                    // exception is in the log, where it belongs.
                    app()->hasDebugModeEnabled() ? $e->getMessage() : 'An unexpected error occurred.',
                ],
            };

            $payload = ['error' => ['code' => $code, 'message' => $message]];

            // Field-level detail is useful to a developer integrating against
            // the API and harmless to a device, which ignores it.
            if ($e instanceof ValidationException) {
                $payload['error']['fields'] = $e->errors();
            }

            return response()->json($payload, $status);
        });
    })->create();
