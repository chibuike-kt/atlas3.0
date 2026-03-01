<?php

use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\IdempotencyMiddleware;
use App\Http\Middleware\VelocityMiddleware;
use App\Http\Middleware\VerifyMonoWebhook;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            ForceJsonResponse::class,
        ]);

        $middleware->alias([
            'auth.jwt'     => \PHPOpenSourceSaver\JWTAuth\Http\Middleware\Authenticate::class,
            'jwt.refresh'  => \PHPOpenSourceSaver\JWTAuth\Http\Middleware\RefreshToken::class,
            'idempotent'   => IdempotencyMiddleware::class,
            'velocity'     => VelocityMiddleware::class,
            'mono.webhook' => VerifyMonoWebhook::class,
        ]);

        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();
