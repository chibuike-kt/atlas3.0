<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\Finance\SalaryAdvanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalaryAdvanceController extends BaseApiController
{
  public function __construct(private readonly SalaryAdvanceService $advanceService) {}

  /**
   * GET /api/advance/eligibility
   * Check if the user is eligible and what the max advance is.
   */
  public function eligibility(Request $request): JsonResponse
  {
    $result = $this->advanceService->checkEligibility($request->user());

    $message = $result['eligible']
      ? "You are eligible for up to {$result['max_amount_formatted']}."
      : $result['reason'];

    return $this->success($result, $message);
  }

  /**
   * POST /api/advance/request
   * Request a salary advance.
   */
  public function request(Request $request): JsonResponse
  {
    $validated = $request->validate([
      'amount' => ['required', 'integer', 'min:1000000'],
    ]);

    try {
      $advance = $this->advanceService->request(
        $request->user(),
        $validated['amount']
      );

      return $this->created(
        $this->formatAdvance($advance),
        "Advance of ₦" . number_format($advance->amount / 100, 2) .
          " disbursed. Repayment of ₦" . number_format($advance->repayment_amount / 100, 2) .
          " will be recovered when your salary arrives."
      );
    } catch (\RuntimeException $e) {
      return $this->error($e->getMessage());
    }
  }

  /**
   * GET /api/advance
   * List all advances for the user.
   */
  public function index(Request $request): JsonResponse
  {
    $advances = $request->user()
      ->salaryAdvances()
      ->orderByDesc('requested_at')
      ->paginate($request->input('per_page', 10));

    return $this->paginated(
      $advances->through(fn($a) => $this->formatAdvance($a)),
      'Advances retrieved.'
    );
  }

  /**
   * GET /api/advance/{id}
   */
  public function show(Request $request, string $id): JsonResponse
  {
    $advance = $request->user()->salaryAdvances()->find($id);

    if (! $advance) {
      return $this->notFound('Advance not found.');
    }

    return $this->success($this->formatAdvance($advance));
  }

  // ── Private helpers ───────────────────────────────────────────────────

  private function formatAdvance(\App\Models\SalaryAdvance $advance): array
  {
    return [
      'id'                    => $advance->id,
      'amount'                => $advance->amount,
      'amount_formatted'      => '₦' . number_format($advance->amount / 100, 2),
      'fee'                   => $advance->fee,
      'fee_formatted'         => '₦' . number_format($advance->fee / 100, 2),
      'repayment_amount'      => $advance->repayment_amount,
      'repayment_formatted'   => '₦' . number_format($advance->repayment_amount / 100, 2),
      'status'                => $advance->status,
      'expected_salary_day'   => $advance->expected_salary_day,
      'due_date'              => $advance->due_date,
      'requested_at'          => $advance->requested_at,
      'disbursed_at'          => $advance->disbursed_at,
      'repaid_at'             => $advance->repaid_at,
    ];
  }
}
