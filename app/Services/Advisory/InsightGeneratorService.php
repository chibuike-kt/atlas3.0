<?php

namespace App\Services\Advisory;

use App\Enums\InsightType;
use App\Models\AdvisoryInsight;
use App\Models\FinancialProfile;
use App\Models\User;
use App\Services\Financial\CashflowProjectionService;
use App\Services\Financial\PersonalInflationTracker;
use Illuminate\Support\Str;

class InsightGeneratorService
{
    public function __construct(
        private readonly CashflowProjectionService $projectionService,
        private readonly PersonalInflationTracker  $inflationTracker
    ) {}

    /**
     * Run all insight checks for a user and queue any new ones.
     * Returns the number of insights generated.
     */
    public function generateForUser(User $user): int
    {
        $profile = $user->financialProfile;

        if (! $profile) {
            return 0;
        }

        // Respect daily insight cap
        $todayCount = $user->insights()
            ->whereDate('created_at', today())
            ->count();

        $maxPerDay = config('atlas.advisory.insight_max_per_day', 3);

        if ($todayCount >= $maxPerDay) {
            return 0;
        }

        $generated = 0;
        $remaining = $maxPerDay - $todayCount;

        $checks = [
            fn() => $this->checkProjectedShortfall($user, $profile),
            fn() => $this->checkLowBalance($user, $profile),
            fn() => $this->checkIdleCash($user, $profile),
            fn() => $this->checkSpendingSpike($user, $profile),
            fn() => $this->checkSavingsRateDrop($user, $profile),
            fn() => $this->checkSalaryArrival($user, $profile),
            fn() => $this->checkUnusualCharge($user, $profile),
            fn() => $this->checkInflation($user, $profile),
            fn() => $this->checkWeeklySnapshot($user, $profile),
        ];

        foreach ($checks as $check) {
            if ($generated >= $remaining) {
                break;
            }

            $insight = $check();

            if ($insight) {
                $generated++;
            }
        }

        return $generated;
    }

    // ── Individual insight checks ─────────────────────────────────────────

    private function checkProjectedShortfall(User $user, FinancialProfile $profile): ?AdvisoryInsight
    {
        if (! $this->canGenerate($user, InsightType::ProjectedShortfall, hours: 24)) {
            return null;
        }

        $projection = $this->projectionService->project($user);

        if (! $projection['has_projected_shortfall']) {
            return null;
        }

        $eomFormatted = '₦' . number_format(abs($projection['projected_eom_balance']) / 100, 2);
        $daysLeft     = $projection['days_remaining_in_month'];

        return $this->create($user, InsightType::ProjectedShortfall, [
            'title' => 'You may run short before month end',
            'body'  => "Based on your spending pace, Atlas projects you could be ₦{$eomFormatted} short by end of month — {$daysLeft} days away. Your average daily spend is " . '₦' . number_format($projection['avg_daily_spend'] / 100, 2) . '. Want Atlas to help you adjust?',
            'cta_label'  => 'See spending breakdown',
            'cta_action' => 'view_spending_summary',
            'data'       => $projection,
            'expires_at' => now()->endOfMonth(),
        ]);
    }

    private function checkLowBalance(User $user, FinancialProfile $profile): ?AdvisoryInsight
    {
        if (! $this->canGenerate($user, InsightType::LowBalanceWarning, hours: 12)) {
            return null;
        }

        $primaryAccount = $user->primaryAccount;

        if (! $primaryAccount) {
            return null;
        }

        $balance         = $primaryAccount->balance;
        $avgMonthlySpend = $profile->avg_monthly_spend ?? 0;
        $warningThreshold = (int) ($avgMonthlySpend * 0.15); // 15% of monthly spend

        if ($balance > $warningThreshold || $balance <= 0) {
            return null;
        }

        $balanceFormatted   = '₦' . number_format($balance / 100, 2);
        $thresholdFormatted = '₦' . number_format($warningThreshold / 100, 2);

        return $this->create($user, InsightType::LowBalanceWarning, [
            'title' => 'Your balance is getting low',
            'body'  => "Your {$primaryAccount->institution} balance is {$balanceFormatted} — below your typical 15% safety buffer of {$thresholdFormatted}. " .
                       ($profile->salary_day ? "Your salary usually arrives around the {$profile->salary_day}th." : 'Consider moving money in or reducing spend.'),
            'cta_label'  => 'Check accounts',
            'cta_action' => 'view_accounts',
            'data'       => ['balance' => $balance, 'threshold' => $warningThreshold],
            'expires_at' => now()->addHours(24),
        ]);
    }

    private function checkIdleCash(User $user, FinancialProfile $profile): ?AdvisoryInsight
    {
        if (! $this->canGenerate($user, InsightType::IdleCash, hours: 72)) {
            return null;
        }

        $idle = $this->projectionService->detectIdleCash($user);

        if (! $idle) {
            return null;
        }

        return $this->create($user, InsightType::IdleCash, [
            'title' => "You have {$idle['idle_amount_formatted']} sitting idle",
            'body'  => "{$idle['idle_amount_formatted']} in your account has barely moved in the last week. " .
                       "Moved to Atlas Flex, it could earn {$idle['projected_monthly_return_formatted']} this month at {$idle['float_rate_percent']}% p.a. — " .
                       "while staying fully accessible.",
            'cta_label'  => 'Move to Atlas Flex',
            'cta_action' => 'move_to_flex',
            'data'       => $idle,
            'expires_at' => now()->addDays(3),
        ]);
    }

    private function checkSpendingSpike(User $user, FinancialProfile $profile): ?AdvisoryInsight
    {
        if (! $this->canGenerate($user, InsightType::SpendingSpike, hours: 48)) {
            return null;
        }

        $avgMonthlySpend = $profile->avg_monthly_spend ?? 0;

        if ($avgMonthlySpend == 0) {
            return null;
        }

        // Compare this month's spend pace vs average
        $daysInMonth  = now()->daysInMonth;
        $daysPassed   = now()->day;
        $spentSoFar   = $user->transactions()
            ->debits()
            ->thisMonth()
            ->sum('amount');

        $projectedMonthly = $daysPassed > 0
            ? (int) (($spentSoFar / $daysPassed) * $daysInMonth)
            : 0;

        $spikeThreshold = $avgMonthlySpend * 1.30; // 30% above average

        if ($projectedMonthly < $spikeThreshold) {
            return null;
        }

        $paceFormatted = '₦' . number_format($projectedMonthly / 100, 2);
        $avgFormatted  = '₦' . number_format($avgMonthlySpend / 100, 2);
        $percentAbove  = round((($projectedMonthly - $avgMonthlySpend) / $avgMonthlySpend) * 100);

        // Find the category driving the spike
        $topCategory = $profile->getTopSpendCategory();

        return $this->create($user, InsightType::SpendingSpike, [
            'title' => "You are spending {$percentAbove}% more than usual",
            'body'  => "At your current pace you will spend {$paceFormatted} this month — {$percentAbove}% above your average of {$avgFormatted}." .
                       ($topCategory ? " Most of it is going to {$topCategory}." : ''),
            'cta_label'  => 'See spending breakdown',
            'cta_action' => 'view_spending_summary',
            'data'       => [
                'projected_monthly' => $projectedMonthly,
                'avg_monthly'       => $avgMonthlySpend,
                'percent_above'     => $percentAbove,
                'top_category'      => $topCategory,
            ],
            'expires_at' => now()->addDays(2),
        ]);
    }

    private function checkSavingsRateDrop(User $user, FinancialProfile $profile): ?AdvisoryInsight
    {
        if (! $this->canGenerate($user, InsightType::SavingsRateDrop, hours: 168)) { // weekly
            return null;
        }

        $savingsRate = $profile->savings_rate_percent ?? 0;
        $target      = config('atlas.advisory.savings_rate_target_percent', 20);

        if ($savingsRate >= $target) {
            return null;
        }

        $gap = round($target - $savingsRate, 1);

        return $this->create($user, InsightType::SavingsRateDrop, [
            'title' => "Your savings rate is below target",
            'body'  => "You are saving {$savingsRate}% of your income — {$gap}% below the recommended {$target}%. " .
                       "A small automated rule to move money on salary day can close this gap without you thinking about it.",
            'cta_label'  => 'Create a savings rule',
            'cta_action' => 'create_savings_rule',
            'data'       => ['current_rate' => $savingsRate, 'target_rate' => $target, 'gap' => $gap],
            'expires_at' => now()->addDays(7),
        ]);
    }

    private function checkSalaryArrival(User $user, FinancialProfile $profile): ?AdvisoryInsight
    {
        if (! $profile->salary_detected) {
            return null;
        }

        if (! $this->canGenerate($user, InsightType::SalaryDetected, hours: 720)) { // once a month
            return null;
        }

        // Check if salary just arrived today or yesterday
        $recentSalary = $user->transactions()
            ->credits()
            ->where('is_salary', true)
            ->where('transaction_date', '>=', now()->subDay()->toDateString())
            ->orderByDesc('transaction_date')
            ->first();

        if (! $recentSalary) {
            return null;
        }

        $amount    = '₦' . number_format($recentSalary->amount / 100, 2);
        $activeRules = $user->rules()->active()->count();

        return $this->create($user, InsightType::SalaryDetected, [
            'title' => "Your salary just arrived — {$amount}",
            'body'  => $activeRules > 0
                ? "Atlas has detected your salary of {$amount}. Your {$activeRules} active rule(s) will run automatically. Sit back."
                : "Atlas has detected your salary of {$amount}. You have no active rules yet. Want Atlas to suggest how to allocate it?",
            'cta_label'  => $activeRules > 0 ? 'View rules' : 'Get suggestions',
            'cta_action' => $activeRules > 0 ? 'view_rules' : 'get_rule_suggestions',
            'data'       => ['amount' => $recentSalary->amount, 'active_rules' => $activeRules],
            'expires_at' => now()->addHours(48),
        ]);
    }

    private function checkUnusualCharge(User $user, FinancialProfile $profile): ?AdvisoryInsight
    {
        if (! $this->canGenerate($user, InsightType::UnusualCharge, hours: 6)) {
            return null;
        }

        $avgMonthlySpend = $profile->avg_monthly_spend ?? 0;

        if ($avgMonthlySpend == 0) {
            return null;
        }

        // Look for a single debit in the last 24 hours that is unusually large
        $largeThreshold = (int) ($avgMonthlySpend * 0.20); // 20% of monthly spend in one hit

        $unusualTx = $user->transactions()
            ->debits()
            ->where('transaction_date', '>=', now()->subDay()->toDateString())
            ->where('amount', '>=', $largeThreshold)
            ->where('is_salary', false)
            ->where('is_atlas_execution', false)
            ->orderByDesc('amount')
            ->first();

        if (! $unusualTx) {
            return null;
        }

        $amountFormatted = '₦' . number_format($unusualTx->amount / 100, 2);
        $percentOfSpend  = round(($unusualTx->amount / $avgMonthlySpend) * 100);

        return $this->create($user, InsightType::UnusualCharge, [
            'title' => "Unusual charge detected — {$amountFormatted}",
            'body'  => "A debit of {$amountFormatted} just hit your account" .
                       ($unusualTx->narration ? " ({$unusualTx->narration})" : '') .
                       ". That is {$percentOfSpend}% of your average monthly spend in a single transaction. Did you authorise this?",
            'cta_label'  => 'Dispute this charge',
            'cta_action' => 'open_dispute',
            'data'       => [
                'transaction_id' => $unusualTx->id,
                'amount'         => $unusualTx->amount,
                'narration'      => $unusualTx->narration,
            ],
            'expires_at' => now()->addHours(48),
        ]);
    }

    private function checkInflation(User $user, FinancialProfile $profile): ?AdvisoryInsight
    {
        if (! $this->canGenerate($user, InsightType::DollarHedgeOpportunity, hours: 168)) {
            return null;
        }

        $inflationRate = $profile->personal_inflation_rate ?? 0;

        if ($inflationRate < 10) {
            return null;
        }

        $worstCategory = null;

        if (! empty($profile->inflation_by_category)) {
            $categories = $profile->inflation_by_category;
            arsort($categories);
            $key   = array_key_first($categories);
            $rate  = $categories[$key];

            if ($rate > 15) {
                $worstCategory = ucfirst($key) . ' costs are up ' . round($rate) . '% vs last month';
            }
        }

        return $this->create($user, InsightType::DollarHedgeOpportunity, [
            'title' => "Your personal inflation is {$inflationRate}% this month",
            'body'  => "Your spending costs have risen {$inflationRate}% compared to last month" .
                       ($worstCategory ? ". {$worstCategory}." : '.') .
                       " Converting idle naira to USDT can protect your purchasing power from further erosion.",
            'cta_label'  => 'Protect against inflation',
            'cta_action' => 'convert_to_usdt',
            'data'       => [
                'inflation_rate'       => $inflationRate,
                'inflation_by_category'=> $profile->inflation_by_category,
            ],
            'expires_at' => now()->addDays(7),
        ]);
    }

    private function checkWeeklySnapshot(User $user, FinancialProfile $profile): ?AdvisoryInsight
    {
        if (! $this->canGenerate($user, InsightType::WeeklySnapshot, hours: 168)) {
            return null;
        }

        // Only generate on Mondays
        if (now()->dayOfWeek !== 1) {
            return null;
        }

        $weekStart = now()->subWeek()->startOfWeek();
        $weekEnd   = now()->subWeek()->endOfWeek();

        $weekSpend = $user->transactions()
            ->debits()
            ->whereBetween('transaction_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->sum('amount');

        $weekIncome = $user->transactions()
            ->credits()
            ->whereBetween('transaction_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->sum('amount');

        $spendFormatted  = '₦' . number_format($weekSpend / 100, 2);
        $incomeFormatted = '₦' . number_format($weekIncome / 100, 2);
        $netFormatted    = '₦' . number_format(abs($weekIncome - $weekSpend) / 100, 2);
        $netLabel        = $weekIncome >= $weekSpend ? 'up' : 'down';

        return $this->create($user, InsightType::WeeklySnapshot, [
            'title' => 'Your week in numbers',
            'body'  => "Last week: {$incomeFormatted} in, {$spendFormatted} out. " .
                       "You finished the week {$netLabel} {$netFormatted}." .
                       ($profile->savings_rate_percent
                           ? " Your savings rate this month is {$profile->savings_rate_percent}% ({$profile->savings_rate_label})."
                           : ''),
            'cta_label'  => 'Full spending breakdown',
            'cta_action' => 'view_spending_summary',
            'data'       => [
                'week_income' => $weekIncome,
                'week_spend'  => $weekSpend,
                'net'         => $weekIncome - $weekSpend,
            ],
            'expires_at' => now()->addDays(7),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Check if this insight type can be generated now.
     * Prevents duplicate insights within a given window.
     */
    private function canGenerate(User $user, InsightType $type, int $hours): bool
    {
        return ! AdvisoryInsight::where('user_id', $user->id)
            ->where('type', $type->value)
            ->where('created_at', '>=', now()->subHours($hours))
            ->exists();
    }

    /**
     * Create and persist an insight.
     */
    private function create(User $user, InsightType $type, array $data): AdvisoryInsight
    {
        return AdvisoryInsight::create([
            'id'             => Str::uuid(),
            'user_id'        => $user->id,
            'type'           => $type->value,
            'title'          => $data['title'],
            'body'           => $data['body'],
            'priority'       => $type->priority(),
            'is_urgent'      => $type->isUrgent(),
            'cta_label'      => $data['cta_label'] ?? null,
            'cta_action'     => $data['cta_action'] ?? null,
            'data'           => $data['data'] ?? null,
            'action_payload' => $data['action_payload'] ?? null,
            'expires_at'     => $data['expires_at'] ?? null,
        ]);
    }
}
