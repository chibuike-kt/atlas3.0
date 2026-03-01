<?php

namespace App\Enums;

enum TriggerType: string
{
  case Schedule = 'schedule';
  case Deposit  = 'deposit';
  case Balance  = 'balance';
  case Manual   = 'manual';
  case Salary   = 'salary';

  public function isScheduled(): bool
  {
    return $this === self::Schedule;
  }

  public function isEventBased(): bool
  {
    return in_array($this, [self::Deposit, self::Balance, self::Salary]);
  }

  public function label(): string
  {
    return match ($this) {
      self::Schedule => 'Scheduled',
      self::Deposit  => 'On Deposit',
      self::Balance  => 'Balance Trigger',
      self::Manual   => 'Manual',
      self::Salary   => 'On Salary Arrival',
    };
  }
}
