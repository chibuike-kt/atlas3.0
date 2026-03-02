<?php

namespace App\Enums;

enum AmountType: string
{
  case Fixed      = 'fixed';
  case Percentage = 'percentage';
  case Remainder  = 'remainder';

  public function label(): string
  {
    return match ($this) {
      self::Fixed      => 'Fixed Amount',
      self::Percentage => 'Percentage of Balance',
      self::Remainder  => 'Remaining Balance',
    };
  }

  public function requiresAmount(): bool
  {
    return $this !== self::Remainder;
  }

  public function isPercentage(): bool
  {
    return $this === self::Percentage;
  }

  public function isFixed(): bool
  {
    return $this === self::Fixed;
  }

  public function isRemainder(): bool
  {
    return $this === self::Remainder;
  }

  public function resolveAmount(int $fixedAmount, int $availableBalance): int
  {
    return match ($this) {
      self::Fixed      => $fixedAmount,
      self::Percentage => (int) round($availableBalance * ($fixedAmount / 10000)),
      self::Remainder  => $availableBalance,
    };
  }
}
