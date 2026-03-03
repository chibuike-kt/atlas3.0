<?php

namespace App\Services\Advisory;

use App\Enums\InsightType;
use App\Models\AdvisoryInsight;
use App\Models\FinancialProfile;
use App\Models\User;
use Illuminate\Support\Str;

class RuleSuggestionService
{
    /**
     * Generate rule suggestions based on the user's financial profile.
     * Each suggestion becomes an InsightType::SuggestedRule insight with
     * a fully-formed action_payload the frontend can send directly to
     * POST /api/rules to create the rule.
     */
    public function generateSuggestions(User $user): int
    {
        $profile = $user->financialProfile;

        if (! $profile) {
            return 0;
        }

        $generated = 0;

        $checks = [
            fn() => $this->suggestSalarySavings($user, $profile),
            fn() => $this->suggestIdleCashSweep($user, $profile),
            fn() => $this->suggestBillAutomation($user, $profile),
            fn() => $this->suggestDollarHedge($user, $profile),
            fn() => $this->suggestAjoContribution($user, $profile),
        ];

        foreach ($checks as $check) {
            $insight = $check();
            if ($insight) {
                $generated++;
            }
        }

        return $generated;
    }

    // ── Individual suggestion generators ─────────────────────────────────

    private function suggestSalarySavings(User $user, FinancialProfile $profile): ?AdvisoryInsight
    {
        if (! $profile->salary_detected) {
            return null;
        }

        if (! $this->canSuggest($user, 'salary_savings')) {
            return null;
        }

        $avgSalary      = $profile->average_salary ?? 0;
        $savingsRate    = $profile->savings_rate_percent ?? 0;
        $targetRate     = config('atlas.advisory.savings_rate_target_percent', 20);
        $account        = $user->primaryAccount;

        if (! $account || $savingsRate >= $targetRate) {
            return null;
        }

        // Suggest saving the gap between current and target rate
        $suggestedRate   = min($targetRate, $savingsRate + 10);
        $suggestedAmount = (int) ($avgSalary * ($suggestedRate / 100));
        $formatted       = '₦' . number_format($suggestedAmount / 100, 2);

        $rulePayload = [
            'name'                  => 'Save on salary day',
            'rule_text'             => "When my salary arrives, save {$suggestedRate}% to PiggyVest",
            'connected_account_id'  => $account->id,
            'trigger_type'          => 'deposit',
            'trigger_config'        => [
                'condition'     => 'is_salary',
                'min_amount'    => (int) ($avgSalary * 0.5),
            ],
            'total_amount_type'     => 'percentage',
            'total_amount'          => $suggestedRate * 100, // basis points
            'actions'               => [
                [
                    'action_type' => 'save_piggvest',
                    'amount_type' => 'percentage',
                    'amount'      => $suggestedRate * 100,
                    'label'       => "Save {$suggestedRate}% to PiggyVest",
                    'step_order'  => 1,
                ],
            ],
        ];

        return $this->createSuggestion($user, [
            'title'          => "Save {$formatted} every salary day automatically",
            'body'           => "You are currently saving {$savingsRate}% of your income — below the recommended {$targetRate}%. " .
                "Atlas can move {$formatted} ({$suggestedRate}% of your salary) to PiggyVest automatically the moment it lands. " .
                "You will not feel it leaving.",
            'cta_label'      => 'Create this rule',
            'cta_action'     => 'create_rule',
            'action_payload' => $rulePayload,
            'data'           => [
                'suggested_amount' => $suggestedAmount,
                'suggested_rate'   => $suggestedRate,
                'current_rate'     => $savingsRate,
            ],
            'suggestion_key' => 'salary_savings',
        ]);
    }

    private function suggestIdleCashSweep(User $user, FinancialProfile $profile): ?AdvisoryInsight
    {
        $account = $user->primaryAccount;

        if (! $account) {
            return null;
        }

        if (! $this->canSuggest($user, 'idle_cash_sweep')) {
            return null;
        }

        $avgMonthlySpend  = $profile->avg_monthly_spend ?? 0;
        $balance          = $account->balance;

        if ($avgMonthlySpend == 0 || $balance < $avgMonthlySpend) {
            return null;
        }

        // Recommend sweeping everything above 1.5x monthly spend
        $safeBuffer      = (int) ($avgMonthlySpend * 1.5);
        $sweepAmount     = $balance - $safeBuffer;

        if ($sweepAmount < 1000000) { // Minimum N10,000
            return null;
        }

        $sweepFormatted  = '₦' . number_format($sweepAmount / 100, 2);
        $bufferFormatted = '₦' . number_format($safeBuffer / 100, 2);

        $rulePayload = [
            'name'                 => 'Weekly idle cash sweep',
            'rule_text'            => 'Every Sunday, save anything above my safety buffer to PiggyVest',
            'connected_account_id' => $account->id,
            'trigger_type'         => 'schedule',
            'trigger_config'       => [
                'frequency' => 'weekly',
                'day'       => 'sunday',
                'time'      => '20:00',
            ],
            'total_amount_type'    => 'remainder',
            'total_amount'         => null,
            'actions'              => [
                [
                    'action_type' => 'save_piggvest',
                    'amount_type' => 'remainder',
                    'amount'      => 0,
                    'label'       => 'Sweep idle cash to PiggyVest',
                    'step_order'  => 1,
                    'config'      => ['keep_buffer' => $safeBuffer],
                ],
            ],
        ];

        return $this->createSuggestion($user, [
            'title'          => "Sweep {$sweepFormatted} of idle cash every Sunday",
            'body'           => "You consistently have more than 1.5x your monthly expenses sitting in your current account. " .
                "Atlas can sweep the excess to PiggyVest every Sunday evening, keeping {$bufferFormatted} as your buffer. " .
                "Idle money should always be working.",
            'cta_label'      => 'Create this rule',
            'cta_action'     => 'create_rule',
            'action_payload' => $rulePayload,
            'data'           => [
                'sweep_amount' => $sweepAmount,
                'buffer'       => $safeBuffer,
            ],
            'suggestion_key' => 'idle_cash_sweep',
        ]);
    }

    private function suggestBillAutomation(User $user, FinancialProfile $profile): ?AdvisoryInsight
    {
        if (! $this->canSuggest($user, 'bill_automation')) {
            return null;
        }

        $account = $user->primaryAccount;

        if (! $account) {
            return null;
        }

        // Look for recurring bill payments in transaction history
        $billSpend = $user->transactions()
            ->debits()
            ->where('is_bill_payment', true)
            ->lastNDays(60)
            ->sum('amount');

        if ($billSpend < 500000) { // Less than N5,000 in bills — not worth suggesting
            return null;
        }

        $billFormatted = '₦' . number_format($billSpend / 100, 2);

        $rulePayload = [
            'name'                 => 'Automate monthly bills',
            'rule_text'            => 'On the 1st of every month, pay my bills automatically',
            'connected_account_id' => $account->id,
            'trigger_type'         => 'schedule',
            'trigger_config'       => [
                'frequency' => 'monthly',
                'day'       => 1,
                'time'      => '08:00',
            ],
            'total_amount_type'    => 'fixed',
            'total_amount'         => (int) ($billSpend / 2), // Approx monthly
            'actions'              => [
                [
                    'action_type' => 'pay_bill',
                    'amount_type' => 'fixed',
                    'amount'      => (int) ($billSpend / 2),
                    'label'       => 'Pay monthly bills',
                    'step_order'  => 1,
                ],
            ],
        ];

        return $this->createSuggestion($user, [
            'title'          => 'Automate your recurring bills',
            'body'           => "Atlas found {$billFormatted} in bill payments over the last 60 days. " .
                "You can set these to run automatically each month so you never miss a payment or get disconnected.",
            'cta_label'      => 'Create this rule',
            'cta_action'     => 'create_rule',
            'action_payload' => $rulePayload,
            'data'           => ['bill_spend_60d' => $billSpend],
            'suggestion_key' => 'bill_automation',
        ]);
    }

    private function suggestDollarHedge(User $user, FinancialProfile $profile): ?AdvisoryInsight
    {
        if (! $this->canSuggest($user, 'dollar_hedge')) {
            return null;
        }

        $inflationRate = $profile->personal_inflation_rate ?? 0;

        if ($inflationRate < 5) {
            return null;
        }

        $account         = $user->primaryAccount;
        $avgSalary       = $profile->average_salary ?? 0;
        $suggestedAmount = (int) ($avgSalary * 0.10); // 10% of salary

        if ($suggestedAmount < 1000000 || ! $account) { // Min N10,000
            return null;
        }

        $formatted = '₦' . number_format($suggestedAmount / 100, 2);

        $rulePayload = [
            'name'                 => 'Dollar hedge on salary day',
            'rule_text'            => "When my salary arrives, convert {$formatted} to USDT",
            'connected_account_id' => $account->id,
            'trigger_type'         => 'deposit',
            'trigger_config'       => [
                'condition'  => 'is_salary',
                'min_amount' => (int) ($avgSalary * 0.5),
            ],
            'total_amount_type'    => 'fixed',
            'total_amount'         => $suggestedAmount,
            'actions'              => [
                [
                    'action_type' => 'convert_crypto',
                    'amount_type' => 'fixed',
                    'amount'      => $suggestedAmount,
                    'label'       => "Convert {$formatted} to USDT",
                    'step_order'  => 1,
                    'config'      => ['token' => 'USDT', 'network' => 'TRC20'],
                ],
            ],
        ];

        return $this->createSuggestion($user, [
            'title'          => "Hedge against inflation with {$formatted} in USDT",
            'body'           => "Your personal inflation rate is {$inflationRate}% this month — your naira is losing purchasing power. " .
                "Converting {$formatted} (10% of salary) to USDT on payday protects that portion from naira depreciation. " .
                "You can convert back any time.",
            'cta_label'      => 'Create this rule',
            'cta_action'     => 'create_rule',
            'action_payload' => $rulePayload,
            'data'           => [
                'suggested_amount' => $suggestedAmount,
                'inflation_rate'   => $inflationRate,
            ],
            'suggestion_key' => 'dollar_hedge',
        ]);
    }

    private function suggestAjoContribution(User $user, FinancialProfile $profile): ?AdvisoryInsight
    {
        if (! $profile->has_ajo_activity) {
            return null;
        }

        if (! $this->canSuggest($user, 'ajo_automation')) {
            return null;
        }

        $account       = $user->primaryAccount;
        $ajoAmount     = $profile->monthly_ajo_obligation ?? 0;

        if ($ajoAmount < 100000 || ! $account) { // Min N1,000
            return null;
        }

        $formatted = '₦' . number_format($ajoAmount / 100, 2);

        $rulePayload = [
            'name'                 => 'Automate ajo contribution',
            'rule_text'            => "On the 25th of every month, send {$formatted} for ajo",
            'connected_account_id' => $account->id,
            'trigger_type'         => 'schedule',
            'trigger_config'       => [
                'frequency' => 'monthly',
                'day'       => 25,
                'time'      => '09:00',
            ],
            'total_amount_type'    => 'fixed',
            'total_amount'         => $ajoAmount,
            'actions'              => [
                [
                    'action_type' => 'save_piggvest',
                    'amount_type' => 'fixed',
                    'amount'      => $ajoAmount,
                    'label'       => "Ajo contribution — {$formatted}",
                    'step_order'  => 1,
                ],
            ],
        ];

        return $this->createSuggestion($user, [
            'title'          => "Never miss your ajo — automate {$formatted}/month",
            'body'           => "Atlas detected your monthly ajo/thrift contribution of {$formatted}. " .
                "Set it to run automatically on the 25th so you are never the member who delays the group.",
            'cta_label'      => 'Automate my ajo',
            'cta_action'     => 'create_rule',
            'action_payload' => $rulePayload,
            'data'           => ['ajo_amount' => $ajoAmount],
            'suggestion_key' => 'ajo_automation',
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function canSuggest(User $user, string $key): bool
    {
        // Do not suggest the same rule type more than once per 14 days
        return ! AdvisoryInsight::where('user_id', $user->id)
            ->where('type', InsightType::SuggestedRule->value)
            ->whereJsonContains('data->suggestion_key', $key)
            ->where('created_at', '>=', now()->subDays(14))
            ->exists();
    }

    private function createSuggestion(User $user, array $data): AdvisoryInsight
    {
        return AdvisoryInsight::create([
            'id'             => Str::uuid(),
            'user_id'        => $user->id,
            'type'           => InsightType::SuggestedRule->value,
            'title'          => $data['title'],
            'body'           => $data['body'],
            'priority'       => InsightType::SuggestedRule->priority(),
            'is_urgent'      => false,
            'cta_label'      => $data['cta_label'],
            'cta_action'     => $data['cta_action'],
            'action_payload' => $data['action_payload'],
            'data'           => array_merge(
                $data['data'] ?? [],
                ['suggestion_key' => $data['suggestion_key']]
            ),
            'expires_at'     => now()->addDays(14),
        ]);
    }
}
