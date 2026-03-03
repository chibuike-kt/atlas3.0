<?php

namespace App\Enums;

enum InsightType: string
{
  case ProjectedShortfall    = 'projected_shortfall';
  case LowBalanceWarning     = 'low_balance_warning';
  case IdleCash              = 'idle_cash';
  case SpendingSpike         = 'spending_spike';
  case SavingsRateDrop       = 'savings_rate_drop';
  case SalaryDetected        = 'salary_detected';
  case UnusualCharge         = 'unusual_charge';
  case DollarHedgeOpportunity = 'dollar_hedge_opportunity';
  case WeeklySnapshot        = 'weekly_snapshot';
  case SuggestedRule         = 'suggested_rule';

  public function priority(): int
  {
    return match ($this) {
      self::ProjectedShortfall     => 1,
      self::LowBalanceWarning      => 2,
      self::UnusualCharge          => 2,
      self::SpendingSpike          => 3,
      self::SalaryDetected         => 3,
      self::IdleCash               => 4,
      self::SavingsRateDrop        => 4,
      self::DollarHedgeOpportunity => 5,
      self::WeeklySnapshot         => 6,
      self::SuggestedRule          => 6,
    };
  }

  public function isUrgent(): bool
  {
    return in_array($this, [
      self::ProjectedShortfall,
      self::LowBalanceWarning,
      self::UnusualCharge,
    ]);
  }

  public function label(): string
  {
    return match ($this) {
      self::ProjectedShortfall     => 'Projected Shortfall',
      self::LowBalanceWarning      => 'Low Balance Warning',
      self::IdleCash               => 'Idle Cash Detected',
      self::SpendingSpike          => 'Spending Spike',
      self::SavingsRateDrop        => 'Savings Rate Drop',
      self::SalaryDetected         => 'Salary Detected',
      self::UnusualCharge          => 'Unusual Charge',
      self::DollarHedgeOpportunity => 'Dollar Hedge Opportunity',
      self::WeeklySnapshot         => 'Weekly Snapshot',
      self::SuggestedRule          => 'Rule Suggestion',
    };
  }
}
