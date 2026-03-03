<?php

namespace App\Enums;

enum ActionType: string
{
  case SendBank      = 'send_bank';
  case SavePiggvest  = 'save_piggvest';
  case SaveCowrywise = 'save_cowrywise';
  case ConvertCrypto = 'convert_crypto';
  case PayBill       = 'pay_bill';

  public function label(): string
  {
    return match ($this) {
      self::SendBank      => 'Bank Transfer',
      self::SavePiggvest  => 'PiggyVest Savings',
      self::SaveCowrywise => 'Cowrywise Investment',
      self::ConvertCrypto => 'Crypto Conversion',
      self::PayBill       => 'Bill Payment',
    };
  }

  public function rail(): string
  {
    return match ($this) {
      self::SendBank      => 'bank_transfer',
      self::SavePiggvest  => 'piggyvest',
      self::SaveCowrywise => 'cowrywise',
      self::ConvertCrypto => 'crypto',
      self::PayBill       => 'bill_payment',
    };
  }

  public function isReversible(): bool
  {
    return match ($this) {
      self::SendBank      => true,
      self::SavePiggvest  => true,
      self::SaveCowrywise => true,
      self::ConvertCrypto => false,
      self::PayBill       => false,
    };
  }
}
