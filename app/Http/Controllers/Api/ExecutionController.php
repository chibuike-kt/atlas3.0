<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Receipt;
use App\Models\RuleExecution;
use App\Services\Execution\ExecutionEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExecutionController extends BaseApiController
{
  public function __construct(private readonly ExecutionEngine $engine) {}

  /**
   * GET /api/executions
   * Full execution history for the authenticated user.
   */
  public function index(Request $request): JsonResponse
  {
    $query = $request->user()
      ->executions()
      ->with(['rule', 'receipt'])
      ->orderByDesc('created_at');

    if ($request->filled('status')) {
      $query->where('status', $request->status);
    }

    if ($request->filled('rule_id')) {
      $query->where('rule_id', $request->rule_id);
    }

    if ($request->filled('from')) {
      $query->where('created_at', '>=', $request->from);
    }

    if ($request->filled('to')) {
      $query->where('created_at', '<=', $request->to);
    }

    $executions = $query->paginate($request->input('per_page', 20));

    return $this->paginated(
      $executions->through(fn($e) => $this->formatExecution($e)),
      'Execution history retrieved.'
    );
  }

  /**
   * GET /api/executions/{id}
   */
  public function show(Request $request, string $id): JsonResponse
  {
    $execution = $request->user()
      ->executions()
      ->with(['rule', 'steps', 'receipt'])
      ->find($id);

    if (! $execution) {
      return $this->notFound('Execution not found.');
    }

    return $this->success($this->formatExecution($execution, true));
  }

  /**
   * POST /api/executions/trigger/{ruleId}
   * Manually trigger a rule execution.
   */
  public function trigger(Request $request, string $ruleId): JsonResponse
  {
    $rule = $request->user()
      ->rules()
      ->find($ruleId);

    if (! $rule) {
      return $this->notFound('Rule not found.');
    }

    if (! $rule->isExecutable()) {
      return $this->error("Rule \"{$rule->name}\" is not active and cannot be executed.");
    }

    try {
      $execution = $this->engine->executeManual($rule, $request->user());

      $message = $execution->status->value === 'completed'
        ? "Rule \"{$rule->name}\" executed successfully."
        : "Execution completed with status: {$execution->status->value}.";

      return $this->success($this->formatExecution($execution, true), $message);
    } catch (\RuntimeException $e) {
      return $this->error($e->getMessage());
    } catch (\Throwable $e) {
      return $this->serverError('Execution failed. Please try again.');
    }
  }

  /**
   * GET /api/executions/{id}/receipt
   */
  public function receipt(Request $request, string $id): JsonResponse
  {
    $execution = $request->user()
      ->executions()
      ->with('receipt')
      ->find($id);

    if (! $execution) {
      return $this->notFound('Execution not found.');
    }

    if (! $execution->receipt) {
      return $this->notFound('No receipt found for this execution.');
    }

    return $this->success($this->formatReceipt($execution->receipt));
  }

  /**
   * GET /api/receipts
   * All receipts for the user.
   */
  public function receipts(Request $request): JsonResponse
  {
    $receipts = $request->user()
      ->receipts()
      ->orderByDesc('issued_at')
      ->paginate($request->input('per_page', 20));

    return $this->paginated(
      $receipts->through(fn($r) => $this->formatReceipt($r)),
      'Receipts retrieved.'
    );
  }

  /**
   * GET /api/receipts/{id}
   */
  public function showReceipt(Request $request, string $id): JsonResponse
  {
    $receipt = $request->user()
      ->receipts()
      ->find($id);

    if (! $receipt) {
      return $this->notFound('Receipt not found.');
    }

    return $this->success($this->formatReceipt($receipt));
  }

  // ── Private helpers ───────────────────────────────────────────────────

  private function formatExecution(RuleExecution $execution, bool $detailed = false): array
  {
    $base = [
      'id'               => $execution->id,
      'status'           => $execution->status,
      'trigger_type'     => $execution->trigger_type,
      'total_amount'     => $execution->total_amount,
      'total_amount_formatted' => $execution->total_amount_formatted,
      'total_fee'        => $execution->total_fee,
      'total_fee_formatted' => $execution->total_fee_formatted,
      'total_debited'    => $execution->total_debited,
      'total_debited_formatted' => $execution->total_debited_formatted,
      'steps_total'      => $execution->steps_total,
      'steps_completed'  => $execution->steps_completed,
      'steps_failed'     => $execution->steps_failed,
      'is_disputed'      => $execution->is_disputed,
      'failure_reason'   => $execution->failure_reason,
      'duration_seconds' => $execution->duration_seconds,
      'started_at'       => $execution->started_at,
      'completed_at'     => $execution->completed_at,
      'rule'             => $execution->rule ? [
        'id'   => $execution->rule->id,
        'name' => $execution->rule->name,
      ] : null,
      'has_receipt'      => (bool) $execution->receipt,
    ];

    if ($detailed) {
      $base['steps']   = $execution->steps ? $execution->steps->map(fn($s) => [
        'id'           => $s->id,
        'step_order'   => $s->step_order,
        'action_type'  => $s->action_type,
        'label'        => $s->label,
        'amount'       => $s->amount,
        'amount_formatted' => $s->amount_formatted,
        'status'       => $s->status,
        'rail_reference' => $s->rail_reference,
        'failure_reason' => $s->failure_reason,
        'completed_at' => $s->completed_at,
      ])->toArray() : [];

      $base['receipt'] = $execution->receipt
        ? $this->formatReceipt($execution->receipt)
        : null;
    }

    return $base;
  }

  private function formatReceipt(Receipt $receipt): array
  {
    return [
      'id'             => $receipt->id,
      'receipt_number' => $receipt->receipt_number,
      'rule_name'      => $receipt->rule_name,
      'total_amount'   => $receipt->total_amount,
      'total_amount_formatted' => $receipt->total_amount_formatted,
      'total_fee'      => $receipt->total_fee,
      'total_fee_formatted' => $receipt->total_fee_formatted,
      'total_debited'  => $receipt->total_debited,
      'total_debited_formatted' => $receipt->total_debited_formatted,
      'currency'       => $receipt->currency,
      'status'         => $receipt->status,
      'steps_summary'  => $receipt->steps_summary,
      'issued_at'      => $receipt->issued_at,
    ];
  }
}
