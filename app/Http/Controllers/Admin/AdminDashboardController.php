<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\BillPayment;
use App\Models\Dispute;
use App\Models\FeeLedger;
use App\Models\Rule;
use App\Models\RuleExecution;
use App\Models\SalaryAdvance;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDashboardController extends BaseApiController
{
  /**
   * GET /api/admin/dashboard
   */
  public function index(): JsonResponse
  {
    $today     = now()->startOfDay();
    $thisMonth = now()->startOfMonth();

    return $this->success([
      'users'      => $this->userStats($today, $thisMonth),
      'executions' => $this->executionStats($today, $thisMonth),
      'revenue'    => $this->revenueStats($today, $thisMonth),
      'disputes'   => $this->disputeStats(),
      'advances'   => $this->advanceStats(),
      'rules'      => $this->ruleStats(),
    ], 'Dashboard retrieved.');
  }

  /**
   * GET /api/admin/dashboard/executions
   * Recent executions across all users.
   */
  public function recentExecutions(Request $request): JsonResponse
  {
    $executions = RuleExecution::with(['user', 'rule'])
      ->orderByDesc('created_at')
      ->paginate($request->input('per_page', 25));

    return $this->paginated(
      $executions->through(fn($e) => [
        'id'           => $e->id,
        'user'         => ['id' => $e->user->id, 'name' => $e->user->full_name],
        'rule_name'    => $e->rule?->name,
        'status'       => $e->status,
        'total_amount' => $e->total_amount,
        'formatted'    => '₦' . number_format($e->total_amount / 100, 2),
        'trigger_type' => $e->trigger_type,
        'created_at'   => $e->created_at,
      ]),
      'Recent executions retrieved.'
    );
  }

  /**
   * GET /api/admin/dashboard/advances
   * Active and overdue advances.
   */
  public function advances(Request $request): JsonResponse
  {
    $query = SalaryAdvance::with('user')
      ->orderByDesc('requested_at');

    if ($request->filled('status')) {
      $query->where('status', $request->status);
    }

    $advances = $query->paginate($request->input('per_page', 25));

    return $this->paginated(
      $advances->through(fn($a) => [
        'id'               => $a->id,
        'user'             => ['id' => $a->user->id, 'name' => $a->user->full_name],
        'amount'           => $a->amount,
        'formatted'        => '₦' . number_format($a->amount / 100, 2),
        'repayment_amount' => $a->repayment_amount,
        'status'           => $a->status,
        'due_date'         => $a->due_date,
        'is_overdue'       => $a->isOverdue(),
        'requested_at'     => $a->requested_at,
      ]),
      'Advances retrieved.'
    );
  }

  // ── Private stats helpers ─────────────────────────────────────────────

  private function userStats($today, $thisMonth): array
  {
    return [
      'total'           => User::count(),
      'active'          => User::where('is_active', true)->count(),
      'new_today'       => User::where('created_at', '>=', $today)->count(),
      'new_this_month'  => User::where('created_at', '>=', $thisMonth)->count(),
      'with_accounts'   => User::whereHas('connectedAccounts')->count(),
    ];
  }

  private function executionStats($today, $thisMonth): array
  {
    return [
      'total'           => RuleExecution::count(),
      'completed'       => RuleExecution::where('status', 'completed')->count(),
      'failed'          => RuleExecution::where('status', 'failed')->count(),
      'today'           => RuleExecution::where('created_at', '>=', $today)->count(),
      'this_month'      => RuleExecution::where('created_at', '>=', $thisMonth)->count(),
      'volume_today'    => RuleExecution::where('created_at', '>=', $today)
        ->where('status', 'completed')
        ->sum('total_amount'),
      'volume_month'    => RuleExecution::where('created_at', '>=', $thisMonth)
        ->where('status', 'completed')
        ->sum('total_amount'),
    ];
  }

  private function revenueStats($today, $thisMonth): array
  {
    return [
      'fees_today'      => FeeLedger::where('charged_at', '>=', $today)->sum('amount'),
      'fees_this_month' => FeeLedger::where('charged_at', '>=', $thisMonth)->sum('amount'),
      'fees_total'      => FeeLedger::sum('amount'),
      'fees_today_formatted'  => '₦' . number_format(FeeLedger::where('charged_at', '>=', $today)->sum('amount') / 100, 2),
      'fees_month_formatted'  => '₦' . number_format(FeeLedger::where('charged_at', '>=', $thisMonth)->sum('amount') / 100, 2),
    ];
  }

  private function disputeStats(): array
  {
    return [
      'open'        => Dispute::where('status', 'open')->count(),
      'under_review' => Dispute::where('status', 'under_review')->count(),
      'resolved'    => Dispute::whereIn('status', ['resolved_refund', 'resolved_no_action'])->count(),
      'total'       => Dispute::count(),
    ];
  }

  private function advanceStats(): array
  {
    return [
      'disbursed'          => SalaryAdvance::where('status', 'disbursed')->count(),
      'total_outstanding'  => SalaryAdvance::where('status', 'disbursed')->sum('repayment_amount'),
      'outstanding_formatted' => '₦' . number_format(
        SalaryAdvance::where('status', 'disbursed')->sum('repayment_amount') / 100,
        2
      ),
      'overdue'            => SalaryAdvance::where('status', 'disbursed')
        ->where('due_date', '<', now())
        ->count(),
      'defaulted'          => SalaryAdvance::where('status', 'defaulted')->count(),
    ];
  }

  private function ruleStats(): array
  {
    return [
      'total'    => Rule::count(),
      'active'   => Rule::where('status', 'active')->count(),
      'paused'   => Rule::where('status', 'paused')->count(),
    ];
  }
}
