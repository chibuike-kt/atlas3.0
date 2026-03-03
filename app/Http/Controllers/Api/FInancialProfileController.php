<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\Financial\CashflowProjectionService;
use App\Services\Financial\FinancialProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialProfileController extends BaseApiController
{
    public function __construct(
        private readonly FinancialProfileService   $profileService,
        private readonly CashflowProjectionService $projectionService
    ) {}

    /**
     * GET /api/financial-profile
     * Returns the full financial profile for the authenticated user.
     */
    public function show(Request $request): JsonResponse
    {
        $user    = $request->user();
        $profile = $user->getOrCreateFinancialProfile();

        // Trigger re-analysis if stale
        if ($profile->is_stale && $user->connectedAccounts()->active()->exists()) {
            $profile = $this->profileService->analyse($user);
        }

        return $this->success($this->formatProfile($profile), 'Financial profile retrieved.');
    }

    /**
     * POST /api/financial-profile/refresh
     * Force a full profile re-analysis.
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->connectedAccounts()->active()->exists()) {
            return $this->error('Connect a bank account before Atlas can analyse your finances.');
        }

        $profile = $this->profileService->analyse($user);

        return $this->success($this->formatProfile($profile), 'Financial profile refreshed.');
    }

    /**
     * GET /api/financial-profile/projection
     * Returns the cashflow projection for the current month.
     */
    public function projection(Request $request): JsonResponse
    {
        $user       = $request->user();
        $projection = $this->projectionService->project($user);

        return $this->success([
            'current_balance'             => $projection['current_balance'],
            'current_balance_formatted'   => '₦' . number_format($projection['current_balance'] / 100, 2),
            'projected_eom_balance'       => $projection['projected_eom_balance'],
            'projected_eom_formatted'     => '₦' . number_format(max(0, $projection['projected_eom_balance']) / 100, 2),
            'spent_this_month'            => $projection['spent_this_month'],
            'spent_this_month_formatted'  => '₦' . number_format($projection['spent_this_month'] / 100, 2),
            'projected_remaining_spend'   => $projection['projected_remaining_spend'],
            'projected_remaining_formatted'=> '₦' . number_format($projection['projected_remaining_spend'] / 100, 2),
            'expected_salary_this_month'  => $projection['expected_salary_this_month'],
            'avg_daily_spend'             => $projection['avg_daily_spend'],
            'avg_daily_spend_formatted'   => '₦' . number_format($projection['avg_daily_spend'] / 100, 2),
            'days_remaining_in_month'     => $projection['days_remaining_in_month'],
            'has_projected_shortfall'     => $projection['has_projected_shortfall'],
            'days_until_shortfall'        => $projection['days_until_shortfall'],
            'cashflow_volatility_score'   => $projection['cashflow_volatility_score'],
            'projected_at'                => $projection['projected_at'],
        ], 'Cashflow projection retrieved.');
    }

    /**
     * GET /api/financial-profile/idle-cash
     * Detects and quantifies idle cash opportunity.
     */
    public function idleCash(Request $request): JsonResponse
    {
        $idle = $this->projectionService->detectIdleCash($request->user());

        if (! $idle) {
            return $this->success(null, 'No idle cash detected right now.');
        }

        return $this->success($idle, 'Idle cash opportunity detected.');
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function formatProfile(\App\Models\FinancialProfile $profile): array
    {
        return [
            'salary' => [
                'detected'          => $profile->salary_detected,
                'day'               => $profile->salary_day,
                'average'           => $profile->average_salary,
                'average_formatted' => $profile->average_salary_formatted,
                'last_amount'       => $profile->last_salary_amount,
                'last_date'         => $profile->last_salary_date,
                'source'            => $profile->salary_source,
                'consistency_score' => $profile->salary_consistency_score,
            ],
            'spending' => [
                'avg_monthly_income'   => $profile->avg_monthly_income,
                'avg_monthly_spend'    => $profile->avg_monthly_spend,
                'avg_monthly_savings'  => $profile->avg_monthly_savings,
                'savings_rate_percent' => $profile->savings_rate_percent,
                'savings_rate_label'   => $profile->savings_rate_label,
                'by_category'          => $profile->spend_by_category,
                'monthly_trend'        => $profile->spend_trend_monthly,
                'income_sources'       => $profile->income_sources,
            ],
            'cashflow' => [
                'projected_eom_balance'       => $profile->projected_eom_balance,
                'projected_eom_formatted'     => $profile->projected_eom_balance_formatted,
                'cashflow_volatility_score'   => $profile->cashflow_volatility_score,
            ],
            'inflation' => [
                'personal_rate'    => $profile->personal_inflation_rate,
                'by_category'      => $profile->inflation_by_category,
            ],
            'health' => [
                'score'     => $profile->financial_health_score,
                'label'     => $profile->health_score_label,
                'breakdown' => $profile->health_score_breakdown,
            ],
            'classification' => [
                'income_type'      => $profile->income_type,
                'has_ajo_activity' => $profile->has_ajo_activity,
                'ajo_obligation'   => $profile->monthly_ajo_obligation,
            ],
            'meta' => [
                'last_analyzed_at'      => $profile->last_analyzed_at,
                'transactions_analyzed' => $profile->transactions_analyzed,
                'is_stale'              => $profile->is_stale,
            ],
        ];
    }
}
