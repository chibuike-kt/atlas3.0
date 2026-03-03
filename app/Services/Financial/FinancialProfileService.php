<?php

namespace App\Services\Financial;

use App\Models\FinancialProfile;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class FinancialProfileService
{
  public function __construct(
    private readonly SalaryCycleDetector      $salaryDetector,
    private readonly CashflowProjectionService $projectionService,
    private readonly PersonalInflationTracker  $inflationTracker
  ) {}

  /**
   * Full profile rebuild — runs all intelligence services and saves results.
   * Called: on first account link, after transaction sync, daily via scheduler.
   */
  public function analyse(User $user): FinancialProfile
  {
    $profile = $user->getOrCreateFinancialProfile();

    try {
      // ── 1. Salary detection ───────────────────────────────────────
      $salaryData = $this->salaryDetector->detect($user);

      // ── 2. Spending patterns ──────────────────────────────────────
      $spendData = $this->analyseSpending($user);

      // ── 3. Cashflow projection ────────────────────────────────────
      $projectionData = $this->projectionService->project($user);

      // ── 4. Personal inflation ─────────────────────────────────────
      $inflationData = $this->inflationTracker->calculate($user);

      // ── 5. Income type classification ─────────────────────────────
      $incomeType = $this->classifyIncomeType($user, $salaryData);

      // ── 6. Financial health score ─────────────────────────────────
      $healthData = $this->calculateHealthScore($spendData, $salaryData, $projectionData);

      // ── 7. Ajo activity detection ─────────────────────────────────
      $ajoData = $this->detectAjoActivity($user);

      // ── Persist all results ───────────────────────────────────────
      $profile->update([
        // Salary
        'salary_detected'          => $salaryData['salary_detected'],
        'salary_day'               => $salaryData['salary_day'],
        'average_salary'           => $salaryData['average_salary'],
        'last_salary_amount'       => $salaryData['last_salary_amount'],
        'last_salary_date'         => $salaryData['last_salary_date'],
        'salary_source'            => $salaryData['salary_source'],
        'salary_consistency_score' => $salaryData['salary_consistency_score'],

        // Spending
        'avg_monthly_income'    => $spendData['avg_monthly_income'],
        'avg_monthly_spend'     => $spendData['avg_monthly_spend'],
        'avg_monthly_savings'   => $spendData['avg_monthly_savings'],
        'savings_rate_percent'  => $spendData['savings_rate_percent'],
        'spend_by_category'     => $spendData['spend_by_category'],
        'spend_trend_monthly'   => $spendData['spend_trend_monthly'],
        'income_sources'        => $spendData['income_sources'],

        // Projection
        'projected_eom_balance'     => $projectionData['projected_eom_balance'],
        'cashflow_volatility_score' => $projectionData['cashflow_volatility_score'],

        // Inflation
        'personal_inflation_rate' => $inflationData['personal_inflation_rate'],
        'inflation_by_category'   => $inflationData['inflation_by_category'],

        // Classification
        'income_type' => $incomeType,

        // Health
        'financial_health_score'    => $healthData['score'],
        'health_score_breakdown'    => $healthData['breakdown'],

        // Ajo
        'has_ajo_activity'       => $ajoData['has_ajo'],
        'monthly_ajo_obligation' => $ajoData['monthly_amount'],

        // Meta
        'last_analyzed_at'       => now(),
        'transactions_analyzed'  => $user->transactions()->count(),
      ]);

      Log::info('Financial profile updated', ['user_id' => $user->id]);
    } catch (\Throwable $e) {
      Log::error('Financial profile analysis failed', [
        'user_id' => $user->id,
        'error'   => $e->getMessage(),
      ]);
    }

    return $profile->fresh();
  }

  // ── Private analysis methods ──────────────────────────────────────────

  private function analyseSpending(User $user): array
  {
    $lookbackMonths = 3;
    $monthlyIncome  = [];
    $monthlySpend   = [];

    for ($i = 0; $i < $lookbackMonths; $i++) {
      $month = now()->subMonths($i);

      $income = $user->transactions()
        ->credits()
        ->whereMonth('transaction_date', $month->month)
        ->whereYear('transaction_date', $month->year)
        ->sum('amount');

      $spend = $user->transactions()
        ->debits()
        ->whereMonth('transaction_date', $month->month)
        ->whereYear('transaction_date', $month->year)
        ->sum('amount');

      $monthlyIncome[] = $income;
      $monthlySpend[]  = $spend;
    }

    $avgIncome  = (int) (array_sum($monthlyIncome) / max(1, count($monthlyIncome)));
    $avgSpend   = (int) (array_sum($monthlySpend) / max(1, count($monthlySpend)));
    $avgSavings = max(0, $avgIncome - $avgSpend);
    $savingsRate = $avgIncome > 0 ? round(($avgSavings / $avgIncome) * 100, 2) : 0;

    // Spend by category — last 30 days
    $spendByCategory = $user->transactions()
      ->debits()
      ->lastNDays(30)
      ->whereNotNull('category')
      ->selectRaw('category, SUM(amount) as total')
      ->groupBy('category')
      ->orderByDesc('total')
      ->pluck('total', 'category')
      ->toArray();

    // Monthly spend trend — last 6 months
    $spendTrend = [];
    for ($i = 5; $i >= 0; $i--) {
      $month = now()->subMonths($i);
      $total = $user->transactions()
        ->debits()
        ->whereMonth('transaction_date', $month->month)
        ->whereYear('transaction_date', $month->year)
        ->sum('amount');

      $spendTrend[] = [
        'month' => $month->format('M Y'),
        'total' => $total,
      ];
    }

    // Income sources
    $incomeSources = $user->transactions()
      ->credits()
      ->lastNDays(90)
      ->selectRaw('category, SUM(amount) as total, COUNT(*) as count')
      ->groupBy('category')
      ->orderByDesc('total')
      ->get()
      ->map(fn($row) => [
        'category' => $row->category ?? 'other',
        'total'    => $row->total,
        'count'    => $row->count,
      ])
      ->toArray();

    return [
      'avg_monthly_income'  => $avgIncome,
      'avg_monthly_spend'   => $avgSpend,
      'avg_monthly_savings' => $avgSavings,
      'savings_rate_percent' => $savingsRate,
      'spend_by_category'   => $spendByCategory,
      'spend_trend_monthly' => $spendTrend,
      'income_sources'      => $incomeSources,
    ];
  }

  private function classifyIncomeType(User $user, array $salaryData): string
  {
    if ($salaryData['salary_detected']) {
      // Check for additional irregular income
      $otherIncome = $user->transactions()
        ->credits()
        ->lastNDays(90)
        ->where('is_salary', false)
        ->where('amount', '>=', 500000) // N5,000+
        ->count();

      return $otherIncome >= 3 ? 'mixed' : 'salaried';
    }

    // Look for regular pattern without salary tag
    $creditCount = $user->transactions()
      ->credits()
      ->lastNDays(90)
      ->where('amount', '>=', 2000000) // N20,000+
      ->count();

    if ($creditCount >= 6) {
      return 'freelance';
    }

    if ($creditCount >= 2) {
      return 'trader';
    }

    return 'unknown';
  }

  private function calculateHealthScore(array $spendData, array $salaryData, array $projectionData): array
  {
    $breakdown = [];
    $totalScore = 0;

    // 1. Savings rate (30 points)
    $savingsRate = $spendData['savings_rate_percent'] ?? 0;
    $savingsScore = min(30, ($savingsRate / 20) * 30);
    $breakdown['savings_rate'] = ['score' => round($savingsScore), 'max' => 30, 'value' => $savingsRate];
    $totalScore += $savingsScore;

    // 2. Salary consistency (25 points)
    $consistencyScore = $salaryData['salary_detected']
      ? min(25, (($salaryData['salary_consistency_score'] ?? 0) / 100) * 25)
      : 0;
    $breakdown['salary_consistency'] = ['score' => round($consistencyScore), 'max' => 25];
    $totalScore += $consistencyScore;

    // 3. Cashflow stability (25 points — inverse of volatility)
    $volatility = $projectionData['cashflow_volatility_score'] ?? 50;
    $stabilityScore = min(25, ((100 - $volatility) / 100) * 25);
    $breakdown['cashflow_stability'] = ['score' => round($stabilityScore), 'max' => 25];
    $totalScore += $stabilityScore;

    // 4. No projected shortfall (20 points)
    $shortfallScore = $projectionData['has_projected_shortfall'] ? 0 : 20;
    $breakdown['no_shortfall'] = ['score' => $shortfallScore, 'max' => 20];
    $totalScore += $shortfallScore;

    return [
      'score'     => round($totalScore, 2),
      'breakdown' => $breakdown,
    ];
  }

  private function detectAjoActivity(User $user): array
  {
    $ajoTransactions = $user->transactions()
      ->where('is_ajo', true)
      ->thisMonth()
      ->sum('amount');

    return [
      'has_ajo'       => $ajoTransactions > 0,
      'monthly_amount' => (int) $ajoTransactions,
    ];
  }
}
