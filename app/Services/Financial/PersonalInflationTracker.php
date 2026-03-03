<?php

namespace App\Services\Financial;

use App\Models\User;

class PersonalInflationTracker
{
    /**
     * Calculate the user's personal inflation rate by comparing
     * average spend per category this month vs last month.
     */
    public function calculate(User $user): array
    {
        $thisMonth = now();
        $lastMonth = now()->subMonth();

        $thisMonthSpend = $this->getMonthlySpendByCategory($user, $thisMonth->month, $thisMonth->year);
        $lastMonthSpend = $this->getMonthlySpendByCategory($user, $lastMonth->month, $lastMonth->year);

        if (empty($lastMonthSpend)) {
            return [
                'personal_inflation_rate' => null,
                'inflation_by_category'   => [],
                'insight'                 => null,
            ];
        }

        $categoryInflation = [];
        $overallChange     = 0;
        $categoryCount     = 0;

        foreach ($thisMonthSpend as $category => $amount) {
            if (! isset($lastMonthSpend[$category]) || $lastMonthSpend[$category] == 0) {
                continue;
            }

            $lastAmount = $lastMonthSpend[$category];
            $change     = (($amount - $lastAmount) / $lastAmount) * 100;

            $categoryInflation[$category] = round($change, 2);
            $overallChange               += $change;
            $categoryCount++;
        }

        $personalInflationRate = $categoryCount > 0
            ? round($overallChange / $categoryCount, 2)
            : 0;

        // Find the category with highest inflation
        $worstCategory = null;

        if (! empty($categoryInflation)) {
            arsort($categoryInflation);
            $worstCategoryKey  = array_key_first($categoryInflation);
            $worstCategoryRate = $categoryInflation[$worstCategoryKey];

            if ($worstCategoryRate > 10) {
                $thisFormatted = '₦' . number_format(($thisMonthSpend[$worstCategoryKey] ?? 0) / 100, 2);
                $lastFormatted = '₦' . number_format(($lastMonthSpend[$worstCategoryKey] ?? 0) / 100, 2);

                $worstCategory = [
                    'category'    => $worstCategoryKey,
                    'change'      => $worstCategoryRate,
                    'this_month'  => $thisFormatted,
                    'last_month'  => $lastFormatted,
                ];
            }
        }

        return [
            'personal_inflation_rate' => $personalInflationRate,
            'inflation_by_category'   => $categoryInflation,
            'worst_category'          => $worstCategory,
            'this_month_total'        => array_sum($thisMonthSpend),
            'last_month_total'        => array_sum($lastMonthSpend),
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function getMonthlySpendByCategory(User $user, int $month, int $year): array
    {
        return $user->transactions()
            ->debits()
            ->whereMonth('transaction_date', $month)
            ->whereYear('transaction_date', $year)
            ->whereNotNull('category')
            ->where('category', '!=', 'financial') // Exclude transfers and fees
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy('category')
            ->pluck('total', 'category')
            ->toArray();
    }
}
