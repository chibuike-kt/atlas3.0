<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use App\Traits\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class IdempotencyMiddleware
{
  use ApiResponse;

  public function handle(Request $request, Closure $next): Response
  {
    if (! in_array($request->method(), ['POST', 'PUT', 'PATCH'])) {
      return $next($request);
    }

    $key = $request->header('X-Idempotency-Key');

    if (! $key) {
      return $next($request);
    }

    if (strlen($key) > 255) {
      return $this->error('X-Idempotency-Key must not exceed 255 characters.', 422);
    }

    $userId = Auth::id();

    $existing = IdempotencyKey::where('key', $key)
      ->where('user_id', $userId)
      ->where('created_at', '>=', now()->subMinutes(config('atlas.security.idempotency_ttl_minutes')))
      ->first();

    if ($existing) {
      return response()->json(array_merge(
        $existing->response_body,
        ['idempotent' => true]
      ), $existing->response_status);
    }

    $response = $next($request);

    if ($response->getStatusCode() < 500) {
      IdempotencyKey::create([
        'key'             => $key,
        'user_id'         => $userId,
        'method'          => $request->method(),
        'path'            => $request->path(),
        'response_status' => $response->getStatusCode(),
        'response_body'   => json_decode($response->getContent(), true) ?? [],
      ]);
    }

    return $response;
  }
}
