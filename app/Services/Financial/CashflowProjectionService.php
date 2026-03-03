<?php

namespace App\Services\Financial;

use App\Models\ConnectedAccount;
use App\Models\FinancialProfile;
use App\Models\User;
use Carbon\Carbon;

class CashflowProjectionService
{
    /**
     * Project the user's end-of-month balance based on:
     * - Current balance
     * - Remaining expected expenses this month
     * - Expected salary if not yet received
     */
    public function project(User $user): array
    {
        $primaryAccount = $user->primaryAccount;

        if (! $primaryAccount) {
            return $this->emptyProjection();
        }

        $profile        = $user->financialProfile;
        $currentBalance = $primaryAccount->balance; // kobo
        $today          = now();
        $daysInMonth    = $today->daysInMonth;
        $daysPassed     = $today->day;
        $daysRemaining  = $daysInMonth - $daysPassed;

        // ── Spending projection ───────────────────────────────────────────

        // What has been spent so far this month
        $spentThisMonth = $user->transactions()
            ->debits()
            ->thisMonth()
            ->where('is_atlas_execution', false)
            ->sum('amount');

        // Average daily spend (from profile or derived from this month)
        $avgMonthlySpend  = $profile->avg_monthly_spend ?? 0;
        $avgDailySpend    = $avgMonthlySpend > 0
            ? (int) ($avgMonthlySpend / $daysInMonth)
            : ($daysPassed > 0 ? (int) ($spentThisMonth / $daysPassed) : 0);

        $projectedRemainingSpend = $avgDailySpend * $daysRemaining;

        // ── Salary projection ─────────────────────────────────────────────

        $expectedSalaryThisMonth = 0;

        if ($profile && $profile->salary_detected) {
            $salaryDay = $profile->salary_day;

            // Check if salary has already arrived this month
            $salaryArrivedThisMonth = $user->transactions()
                ->credits()
                ->where('is_salary', true)
                ->thisMonth()
                ->exists();

            if (! $salaryArrivedThisMonth && $salaryDay >= $today->day) {
                $expectedSalaryThisMonth = $profile->average_salary ?? 0;
            }
        }

        // ── Projection calculation ────────────────────────────────────────

        $projectedEom = $currentBalance
            + $expectedSalaryThisMonth
            - $projectedRemainingSpend;

        // ── Volatility score (0-100, higher = more volatile) ─────────────

        $volatilityScore = $this->calculateVolatilityScore($user);

        // ── Shortfall detection ───────────────────────────────────────────

        $hasProjectedShortfall = $projectedEom < 0;

        $daysUntilShortfall = null;

        if ($currentBalance > 0 && $avgDailySpend > 0) {
            $daysUntilShortfall = (int) floor($currentBalance / $avgDailySpend);

            if ($daysUntilShortfall > $daysRemaining) {
                $daysUntilShortfall = null; // Will not run out this month
            }
        }

        return [
            'current_balance'             => $currentBalance,
            'projected_eom_balance'       => (int) $projectedEom,
            'projected_remaining_spend'   => (int) $projectedRemainingSpend,
            'spent_this_month'            => (int) $spentThisMonth,
            'expected_salary_this_month'  => (int) $expectedSalaryThisMonth,
            'avg_daily_spend'             => (int) $avgDailySpend,
            'days_remaining_in_month'     => $daysRemaining,
            'has_projected_shortfall'     => $hasProjectedShortfall,
            'days_until_shortfall'        => $daysUntilShortfall,
            'cashflow_volatility_score'   => $volatilityScore,
            'projected_at'                => now()->toISOString(),
        ];
    }

    /**
     * Calculate cashflow volatility — how unpredictable is income/spend.
     * 0 = perfectly stable, 100 = highly unpredictable.
     */
    public function calculateVolatilityScore(User $user): float
    {
        // Get last 3 months of monthly income totals
        $monthlyData = [];

        for ($i = 0; $i < 3; $i++) {
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

            $monthlyData[] = ['income' => $income, 'spend' => $spend];
        }

        if (empty($monthlyData)) {
            return 50.0; // Unknown — return medium score
        }

        $incomes = array_column($monthlyData, 'income');
        $spends  = array_column($monthlyData, 'spend');

        $incomeVariance = $this->coefficientOfVariation($incomes);
        $spendVariance  = $this->coefficientOfVariation($spends);

        // Weighted: income stability matters more than spend stability
        $score = ($incomeVariance * 0.6) + ($spendVariance * 0.4);

        return min(100, round($score, 2));
    }

    /**
     * Detect idle cash — money sitting in current account that could be working.
     */
    public function detectIdleCash(User $user): ?array
    {
        $primaryAccount = $user->primaryAccount;

        if (! $primaryAccount) {
            return null;
        }

        $balance   = $primaryAccount->balance;
        $threshold = config('atlas.advisory.idle_cash_minimum_amount', 1000000); // N10,000
        $days      = config('atlas.advisory.idle_cash_threshold_days', 7);

        if ($balance < $threshold) {
            return null;
        }

        // Check if balance has been largely unchanged for N days
        $recentActivity = $user->transactions()
            ->where('connected_account_id', $primaryAccount->id)
            ->where('transaction_date', '>=', now()->subDays($days)->toDateString())
            ->sum('amount');

        $activityRatio = $balance > 0 ? $recentActivity / $balance : 1;

        // If less than 20% of balance has moved in the last N days — it's idle
        if ($activityRatio < 0.20) {
            $profile        = $user->financialProfile;
            $avgMonthlySpend = $profile->avg_monthly_spend ?? 0;

            // Recommend keeping 1.5x monthly spend as buffer, rest is idle
            $recommendedBuffer = (int) ($avgMonthlySpend * 1.5);
            $idleAmount        = max(0, $balance - $recommendedBuffer);

            if ($idleAmount < $threshold) {
                return null;
            }

            $floatRate = (float) \App\Models\SystemSetting::getValue('atlas_float_rate', 18);
            $annualReturn = (int) ($idleAmount * ($floatRate / 100));
            $monthlyReturn = (int) ($annualReturn / 12);

            return [
                'idle_amount'           => $idleAmount,
                'idle_amount_formatted' => '₦' . number_format($idleAmount / 100, 2),
                'recommended_buffer'    => $recommendedBuffer,
                'float_rate_percent'    => $floatRate,
                'projected_monthly_return' => $monthlyReturn,
                'projected_monthly_return_formatted' => '₦' . number_format($monthlyReturn / 100, 2),
                'projected_annual_return'  => $annualReturn,
            ];
        }

        return null;
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function coefficientOfVariation(array $values): float
    {
        $values = array_filter($values);

        if (count($values) < 2) {
            return 0;
        }

        $mean = array_sum($values) / count($values);

        if ($mean == 0) {
            return 0;
        }

        $variance = array_sum(array_map(fn($v) => pow($v - $mean, 2), $values)) / count($values);
        $stdDev   = sqrt($variance);

        return ($stdDev / $mean) * 100;
    }

    private function emptyProjection(): array
    {
        return [
            'current_balance'             => 0,
            'projected_eom_balance'       => 0,
            'projected_remaining_spend'   => 0,
            'spent_this_month'            => 0,
            'expected_salary_this_month'  => 0,
            'avg_daily_spend'             => 0,
            'days_remaining_in_month'     => 0,
            'has_projected_shortfall'     => false,
            'days_until_shortfall'        => null,
            'cashflow_volatility_score'   => 0,
            'projected_at'                => now()->toISOString(),
        ];
    }
}
