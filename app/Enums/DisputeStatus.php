<?php

namespace App\Enums;

enum DisputeStatus: string
{
  case Open            = 'open';
  case UnderReview     = 'under_review';
  case ResolvedRefund  = 'resolved_refund';
  case ResolvedNoAction = 'resolved_no_action';
  case Closed          = 'closed';

  public function isOpen(): bool
  {
    return in_array($this, [self::Open, self::UnderReview]);
  }

  public function label(): string
  {
    return match ($this) {
      self::Open              => 'Open',
      self::UnderReview       => 'Under Review',
      self::ResolvedRefund    => 'Resolved — Refunded',
      self::ResolvedNoAction  => 'Resolved — No Action',
      self::Closed            => 'Closed',
    };
  }
}
