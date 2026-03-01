<?php

namespace App\Exceptions;

use App\Traits\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    use ApiResponse;

    protected $dontReport = [
        //
    ];

    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    public function render($request, Throwable $e)
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return $this->handleApiException($request, $e);
        }

        return parent::render($request, $e);
    }

    private function handleApiException(Request $request, Throwable $e)
    {
        if ($e instanceof ValidationException) {
            return $this->validationError($e->errors());
        }

        if ($e instanceof ModelNotFoundException) {
            $model   = class_basename($e->getModel());
            return $this->notFound("The requested {$model} was not found.");
        }

        if ($e instanceof AuthenticationException) {
            return $this->unauthorized('You must be authenticated to access this resource.');
        }

        if ($e instanceof TokenExpiredException) {
            return $this->unauthorized('Your session has expired. Please log in again.');
        }

        if ($e instanceof TokenInvalidException) {
            return $this->unauthorized('Invalid authentication token.');
        }

        if ($e instanceof JWTException) {
            return $this->unauthorized('Authentication token is missing or malformed.');
        }

        if ($e instanceof NotFoundHttpException) {
            return $this->notFound('The endpoint you requested does not exist.');
        }

        if ($e instanceof MethodNotAllowedHttpException) {
            return $this->error('HTTP method not allowed for this endpoint.', 405);
        }

        if ($e instanceof HttpException) {
            return $this->error($e->getMessage() ?: 'An HTTP error occurred.', $e->getStatusCode());
        }

        // In production, never leak exception details
        if (app()->isProduction()) {
            return $this->error('An unexpected error occurred. Our team has been notified.', 500);
        }

        return $this->error($e->getMessage(), 500);
    }
}
