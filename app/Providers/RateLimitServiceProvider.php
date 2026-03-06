<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class RateLimitServiceProvider extends ServiceProvider
{
  public function boot(): void
  {
    // General API — 120 requests per minute per user
    RateLimiter::for('api', function (Request $request) {
      return $request->user()
        ? Limit::perMinute(120)->by($request->user()->id)
        : Limit::perMinute(30)->by($request->ip());
    });

    // Auth endpoints — strict to prevent brute force
    RateLimiter::for('auth', function (Request $request) {
      return Limit::perMinute(10)->by($request->ip());
    });

    // PIN verification — 5 attempts per 15 minutes
    RateLimiter::for('pin', function (Request $request) {
      return Limit::perMinutes(15, 5)->by(
        ($request->user()?->id ?? $request->ip())
      );
    });

    // Rule execution — 10 manual triggers per minute per user
    RateLimiter::for('execution', function (Request $request) {
      return $request->user()
        ? Limit::perMinute(10)->by('exec:' . $request->user()->id)
        : Limit::perMinute(3)->by($request->ip());
    });

    // Chat — 30 messages per minute per user
    RateLimiter::for('chat', function (Request $request) {
      return $request->user()
        ? Limit::perMinute(30)->by('chat:' . $request->user()->id)
        : Limit::perMinute(5)->by($request->ip());
    });

    // Bill payments — 20 per minute per user
    RateLimiter::for('bills', function (Request $request) {
      return $request->user()
        ? Limit::perMinute(20)->by('bills:' . $request->user()->id)
        : Limit::perMinute(5)->by($request->ip());
    });

    // Mono webhook — high volume, by IP
    RateLimiter::for('webhook', function (Request $request) {
      return Limit::perMinute(300)->by($request->ip());
    });

    // Advance requests — 3 per day per user
    RateLimiter::for('advance', function (Request $request) {
      return $request->user()
        ? Limit::perDay(3)->by('advance:' . $request->user()->id)
        : Limit::perMinute(1)->by($request->ip());
    });
  }
}
