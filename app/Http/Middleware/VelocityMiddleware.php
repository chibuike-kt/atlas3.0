<?php

namespace App\Http\Middleware;

use App\Traits\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class VelocityMiddleware
{
  use ApiResponse;

  public function handle(Request $request, Closure $next, string $type = 'general'): Response
  {
    $userId = Auth::id();

    if (! $userId) {
      return $next($request);
    }

    $config  = config('atlas.security.velocity');
    $limited = false;
    $message = '';

    switch ($type) {
      case 'execution':
        $hourKey  = "velocity:exec:hour:{$userId}";
        $dayKey   = "velocity:exec:day:{$userId}";
        $hourCount = Cache::get($hourKey, 0);
        $dayCount  = Cache::get($dayKey, 0);

        if ($hourCount >= $config['max_executions_per_hour']) {
          $limited = true;
          $message = 'You have reached the maximum number of rule executions per hour. Please wait before running another rule.';
        } elseif ($dayCount >= $config['max_executions_per_day']) {
          $limited = true;
          $message = 'You have reached the daily rule execution limit. This resets at midnight.';
        }

        if (! $limited) {
          Cache::put($hourKey, $hourCount + 1, now()->addHour());
          Cache::put($dayKey,  $dayCount + 1,  now()->endOfDay());
        }
        break;

      case 'transfer':
        $transferKey   = "velocity:transfer:hour:{$userId}";
        $transferCount = Cache::get($transferKey, 0);

        if ($transferCount >= $config['max_transfers_per_hour']) {
          $limited = true;
          $message = 'Too many transfers in the last hour. Please wait a moment before sending again.';
        }

        if (! $limited) {
          Cache::put($transferKey, $transferCount + 1, now()->addHour());
        }
        break;
    }

    if ($limited) {
      return $this->error($message, 429);
    }

    return $next($request);
  }
}
