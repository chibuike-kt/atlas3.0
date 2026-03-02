<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialProfile extends Model
{
  use HasFactory, HasUuids;

  protected $fillable = [
    'user_id',
    'salary_detected',
    'salary_day',
    'average_salary',
    'last_salary_amount',
    'last_salary_date',
    'salary_source',
    'salary_consistency_score',
    'avg_monthly_income',
    'avg_monthly_spend',
    'avg_monthly_savings',
    'savings_rate_percent',
    'spend_by_category',
    'spend_trend_monthly',
    'income_sources',
    'projected_eom_balance',
    'cashflow_volatility_score',
    'income_type',
    'personal_inflation_rate',
    'inflation_by_category',
    'financial_health_score',
    'health_score_breakdown',
    'has_ajo_activity',
    'monthly_ajo_obligation',
    'last_analyzed_at',
    'transactions_analyzed',
  ];

  protected function casts(): array
  {
    return [
      'salary_detected'           => 'boolean',
      'salary_day'                => 'integer',
      'average_salary'            => 'integer',
      'last_salary_amount'        => 'integer',
      'last_salary_date'          => 'date',
      'salary_consistency_score'  => 'float',
      'avg_monthly_income'        => 'integer',
      'avg_monthly_spend'         => 'integer',
      'avg_monthly_savings'       => 'integer',
      'savings_rate_percent'      => 'float',
      'spend_by_category'         => 'array',
      'spend_trend_monthly'       => 'array',
      'income_sources'            => 'array',
      'projected_eom_balance'     => 'integer',
      'cashflow_volatility_score' => 'float',
      'personal_inflation_rate'   => 'float',
      'inflation_by_category'     => 'array',
      'financial_health_score'    => 'float',
      'health_score_breakdown'    => 'array',
      'has_ajo_activity'          => 'boolean',
      'monthly_ajo_obligation'    => 'integer',
      'last_analyzed_at'          => 'datetime',
      'transactions_analyzed'     => 'integer',
    ];
  }

  // ── Relationships ─────────────────────────────────────────────────────

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

  // ── Accessors ─────────────────────────────────────────────────────────

  public function getAverageSalaryFormattedAttribute(): string
  {
    return '₦' . number_format(($this->average_salary ?? 0) / 100, 2);
  }

  public function getProjectedEomBalanceFormattedAttribute(): string
  {
    return '₦' . number_format(($this->projected_eom_balance ?? 0) / 100, 2);
  }

  public function getIsStaleAttribute(): bool
  {
    if (! $this->last_analyzed_at) {
      return true;
    }

    return $this->last_analyzed_at->diffInHours(now()) >= 24;
  }

  public function getSavingsRateLabelAttribute(): string
  {
    $rate = $this->savings_rate_percent ?? 0;

    return match (true) {
      $rate >= 30 => 'Excellent',
      $rate >= 20 => 'Good',
      $rate >= 10 => 'Fair',
      default     => 'Needs Attention',
    };
  }

  public function getHealthScoreLabelAttribute(): string
  {
    $score = $this->financial_health_score ?? 0;

    return match (true) {
      $score >= 80 => 'Excellent',
      $score >= 60 => 'Good',
      $score >= 40 => 'Fair',
      default      => 'Needs Attention',
    };
  }

  // ── Scopes ────────────────────────────────────────────────────────────

  public function scopeStale($query)
  {
    return $query->where(function ($q) {
      $q->whereNull('last_analyzed_at')
        ->orWhere('last_analyzed_at', '<=', now()->subHours(24));
    });
  }

  public function scopeWithSalary($query)
  {
    return $query->where('salary_detected', true);
  }

  // ── Helpers ───────────────────────────────────────────────────────────

  public function getTopSpendCategory(): ?string
  {
    if (empty($this->spend_by_category)) {
      return null;
    }

    arsort($arr = $this->spend_by_category);
    return array_key_first($arr);
  }

  public function getSpendForCategory(string $category): int
  {
    return $this->spend_by_category[$category] ?? 0;
  }
}
