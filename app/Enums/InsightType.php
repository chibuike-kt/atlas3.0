<?php

namespace App\Enums;

enum InsightType: string
{
  case BalanceAlert       = 'balance_alert';
  case SpendingAnomaly    = 'spending_anomaly';
  case SavingsOpportunity = 'savings_opportunity';
  case SalaryArrived      = 'salary_arrived';
  case BillDue            = 'bill_due';
  case IdleCash           = 'idle_cash';
  case ProjectionShortfall = 'projection_shortfall';
  case SuggestedRule      = 'suggested_rule';
  case WeeklyReview       = 'weekly_review';
  case MonthlyReview      = 'monthly_review';
  case DollarHedge        = 'dollar_hedge';
  case InflationAlert     = 'inflation_alert';

  public function isUrgent(): bool
  {
    return in_array($this, [
      self::BalanceAlert,
      self::ProjectionShortfall,
      self::BillDue,
    ]);
  }

  public function label(): string
  {
    return match ($this) {
      self::BalanceAlert        => 'Low Balance Warning',
      self::SpendingAnomaly     => 'Unusual Spending',
      self::SavingsOpportunity  => 'Savings Opportunity',
      self::SalaryArrived       => 'Salary Arrived',
      self::BillDue             => 'Bill Due Soon',
      self::IdleCash            => 'Idle Cash Detected',
      self::ProjectionShortfall => 'Projected Shortfall',
      self::SuggestedRule       => 'Rule Suggestion',
      self::WeeklyReview        => 'Weekly Review',
      self::MonthlyReview       => 'Monthly Review',
      self::DollarHedge         => 'Dollar Hedge Opportunity',
      self::InflationAlert      => 'Purchasing Power Alert',
    };
  }
}
