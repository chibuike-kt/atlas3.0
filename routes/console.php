<?php

use App\Console\Commands\RunAdvisoryEngine;
use App\Console\Commands\RunScheduledRules;
use App\Console\Commands\SyncAccountBalances;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Atlas Scheduler
|--------------------------------------------------------------------------
| Add this single cron entry to your server:
|   * * * * * cd /path-to-atlas && php artisan schedule:run >> /dev/null 2>&1
|--------------------------------------------------------------------------
*/

// Every minute — check for due rules
Schedule::command(RunScheduledRules::class)
  ->everyMinute()
  ->withoutOverlapping()
  ->runInBackground();

// Every hour — sync account balances from Mono
Schedule::command(SyncAccountBalances::class)
  ->hourly()
  ->withoutOverlapping()
  ->runInBackground();

// Every 6 hours — run advisory engine for all users
Schedule::command(RunAdvisoryEngine::class)
  ->everySixHours()
  ->withoutOverlapping()
  ->runInBackground();

// Daily midnight — purge expired keys and tokens
Schedule::call(function () {
  \App\Models\IdempotencyKey::expired()->delete();
  \App\Models\RefreshToken::expired()->delete();
  \Log::info('Atlas cleanup: expired keys purged');
})->daily()->at('00:00');

// Daily 6am — rebuild stale financial profiles
Schedule::call(function () {
  $profileService = app(\App\Services\Financial\FinancialProfileService::class);

  \App\Models\User::where('is_active', true)
    ->whereHas('financialProfile', fn($q) => $q->stale())
    ->each(function ($user) use ($profileService) {
      try {
        $profileService->analyse($user);
      } catch (\Throwable $e) {
        \Log::error('Profile rebuild failed', [
          'user_id' => $user->id,
          'error'   => $e->getMessage(),
        ]);
      }
    });
})->daily()->at('06:00');
