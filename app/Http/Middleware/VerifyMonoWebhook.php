<?php

namespace App\Http\Middleware;

use App\Traits\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyMonoWebhook
{
  use ApiResponse;

  public function handle(Request $request, Closure $next): Response
  {
    $signature = $request->header('mono-webhook-secret');
    $secret    = config('atlas.mono.webhook_secret');

    if (! $secret) {
      return $next($request);
    }

    if (! $signature || ! hash_equals($secret, $signature)) {
      return $this->error('Webhook signature verification failed.', 401);
    }

    return $next($request);
  }
}
