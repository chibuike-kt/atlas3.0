<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SanitizeInputMiddleware
{
  /**
   * Fields that should never be trimmed (passwords, tokens, keys).
   */
  private array $exempt = [
    'password',
    'password_confirmation',
    'current_password',
    'pin',
    'token',
    'api_key',
    'secret',
  ];

  public function handle(Request $request, Closure $next): Response
  {
    $input = $request->all();
    $request->merge($this->clean($input));

    return $next($request);
  }

  private function clean(array $data): array
  {
    foreach ($data as $key => $value) {
      if (in_array($key, $this->exempt, true)) {
        continue;
      }

      if (is_array($value)) {
        $data[$key] = $this->clean($value);
      } elseif (is_string($value)) {
        // Trim whitespace and strip null bytes
        $data[$key] = str_replace("\0", '', trim($value));
      }
    }

    return $data;
  }
}
