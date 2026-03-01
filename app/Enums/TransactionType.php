<?php
namespace  App\Enums;

enum TransactionType: string
{
  case Credit = 'credit';
  case Debit = 'debit';

  public function label(): string
  {
    return match ($this) {
      self::Credit => 'Credit',
      self::Debit => 'Debit',
    };
  }

  public function isCrdit(): bool
  {
    return $this === self::Credit;
  }
}
