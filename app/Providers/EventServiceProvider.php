<?php

namespace App\Providers;

use App\Events\AdvanceDisbursed;
use App\Events\AdvanceRepaid;
use App\Events\DisputeResolved;
use App\Events\ExecutionCompleted;
use App\Events\ExecutionFailed;
use App\Events\LowBalanceDetected;
use App\Events\SalaryDetected;
use App\Listeners\SendPushNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
  protected $listen = [
    ExecutionCompleted::class  => [SendPushNotification::class],
    ExecutionFailed::class     => [SendPushNotification::class],
    SalaryDetected::class      => [SendPushNotification::class],
    LowBalanceDetected::class  => [SendPushNotification::class],
    DisputeResolved::class     => [SendPushNotification::class],
    AdvanceDisbursed::class    => [SendPushNotification::class],
    AdvanceRepaid::class       => [SendPushNotification::class],
  ];

  public function boot(): void {}

  public function shouldDiscoverEvents(): bool
  {
    return false;
  }
}
