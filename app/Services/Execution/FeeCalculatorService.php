<?php

namespace App\Services\Execution;

use App\Models\Rule;
use App\Models\SystemSetting;

class FeeCalculatorService
{
    /**
     * Calculate the total fee for a rule execution.
     * Fee is based on the number of steps (actions) in the rule.
     */
    public function calculate(Rule $rule): int
    {
        $stepCount = count($rule->actions ?? []);
        $tiers     = config('atlas.fees.execution', []);

        foreach ($tiers as $tier) {
            if ($stepCount <= $tier['max_steps']) {
                return $tier['amount_kobo'];
            }
        }

        // Fallback to highest tier
        return (int) SystemSetting::getValue('execution_fee_tier_3', 5000);
    }

    /**
     * Calculate FX spread fee for crypto conversions.
     * Applied as a percentage of the naira amount being converted.
     */
    public function calculateFxSpread(int $amountKobo): int
    {
        $spread = config('atlas.fees.crypto_fx_spread', 0.005);
        return (int) round($amountKobo * $spread);
    }

    /**
     * Calculate salary advance fee.
     */
    public function calculateSalaryAdvanceFee(int $amountKobo): int
    {
        $rate = config('atlas.fees.salary_advance_rate', 0.03);
        return (int) round($amountKobo * $rate);
    }

    /**
     * Get the fee breakdown for display.
     */
    public function breakdown(Rule $rule): array
    {
        $fee       = $this->calculate($rule);
        $stepCount = count($rule->actions ?? []);

        return [
            'execution_fee'   => $fee,
            'step_count'      => $stepCount,
            'fee_formatted'   => '₦' . number_format($fee / 100, 2),
            'fee_description' => "Execution fee for {$stepCount} step(s)",
        ];
    }
}
