<?php

use App\Http\Middleware\ForceJsonMiddleware;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Middleware\SanitizeInputMiddleware;
use App\Http\Middleware\IdempotencyMiddleware;
use App\Http\Middleware\VelocityMiddleware;
use App\Http\Middleware\VerifyMonoWebhook;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware) {

        $middleware->api(prepend: [
            ForceJsonMiddleware::class,
            SecurityHeadersMiddleware::class,
            SanitizeInputMiddleware::class,
        ]);

        $middleware->alias([
            'idempotent'   => IdempotencyMiddleware::class,
            'velocity'     => VelocityMiddleware::class,
            'mono.webhook' => VerifyMonoWebhook::class,
            'admin'        => \App\Http\Middleware\AdminMiddleware::class,
        ]);

        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions) {

        $exceptions->render(function (AuthenticationException $e, $request) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authenticated. Please log in.',
                'data'    => null,
            ], 401);
        });

        $exceptions->render(function (ThrottleRequestsException $e, $request) {
            return response()->json([
                'success' => false,
                'message' => 'Too many requests. Please slow down.',
                'data'    => [
                    'retry_after' => $e->getHeaders()['Retry-After'] ?? null,
                ],
            ], 429);
        });

        $exceptions->render(function (ValidationException $e, $request) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'data'    => $e->errors(),
            ], 422);
        });

        $exceptions->render(function (NotFoundHttpException $e, $request) {
            return response()->json([
                'success' => false,
                'message' => 'The requested resource was not found.',
                'data'    => null,
            ], 404);
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, $request) {
            return response()->json([
                'success' => false,
                'message' => 'HTTP method not allowed for this endpoint.',
                'data'    => null,
            ], 405);
        });

        $exceptions->render(function (\Throwable $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                $message = app()->isProduction()
                    ? 'An internal error occurred. Please try again.'
                    : $e->getMessage();

                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'data'    => app()->isProduction() ? null : [
                        'exception' => get_class($e),
                        'file'      => $e->getFile(),
                        'line'      => $e->getLine(),
                    ],
                ], 500);
            }
        });
    })
    ->create();
