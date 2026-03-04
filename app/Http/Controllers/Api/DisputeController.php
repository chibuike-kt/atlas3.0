<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Dispute;
use App\Services\DisputeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DisputeController extends BaseApiController
{
  public function __construct(private readonly DisputeService $disputeService) {}

  /**
   * GET /api/disputes
   */
  public function index(Request $request): JsonResponse
  {
    $disputes = $request->user()
      ->disputes()
      ->with('execution')
      ->orderByDesc('opened_at')
      ->paginate($request->input('per_page', 15));

    return $this->paginated(
      $disputes->through(fn($d) => $this->formatDispute($d)),
      'Disputes retrieved.'
    );
  }

  /**
   * GET /api/disputes/{id}
   */
  public function show(Request $request, string $id): JsonResponse
  {
    $dispute = $this->findDispute($request, $id);

    if (! $dispute) {
      return $this->notFound('Dispute not found.');
    }

    return $this->success($this->formatDispute($dispute, true));
  }

  /**
   * POST /api/disputes
   */
  public function store(Request $request): JsonResponse
  {
    $validated = $request->validate([
      'execution_id' => ['required', 'string'],
      'reason' => ['required', 'string', 'in:not_authorised,wrong_amount,wrong_recipient,duplicate,service_not_received,technical_error,other'],
      'description'  => ['sometimes', 'nullable', 'string', 'max:500'],
    ]);

    $execution = $request->user()
      ->executions()
      ->find($validated['execution_id']);

    if (! $execution) {
      return $this->notFound('Execution not found.');
    }

    try {
      $dispute = $this->disputeService->open($request->user(), $execution, $validated);

      return $this->created($this->formatDispute($dispute, true), 'Dispute opened. Atlas will review within 24–48 hours.');
    } catch (\RuntimeException $e) {
      return $this->error($e->getMessage());
    }
  }

  /**
   * POST /api/disputes/{id}/evidence
   */
  public function addEvidence(Request $request, string $id): JsonResponse
  {
    $dispute = $this->findDispute($request, $id);

    if (! $dispute) {
      return $this->notFound('Dispute not found.');
    }

    $validated = $request->validate([
      'message' => ['required', 'string', 'max:1000'],
    ]);

    try {
      $dispute = $this->disputeService->addEvidence($dispute, $validated['message']);

      return $this->success($this->formatDispute($dispute, true), 'Evidence added.');
    } catch (\RuntimeException $e) {
      return $this->error($e->getMessage());
    }
  }

  /**
   * POST /api/disputes/{id}/cancel
   */
  public function cancel(Request $request, string $id): JsonResponse
  {
    $dispute = $this->findDispute($request, $id);

    if (! $dispute) {
      return $this->notFound('Dispute not found.');
    }

    try {
      $dispute = $this->disputeService->cancel($dispute);

      return $this->success($this->formatDispute($dispute), 'Dispute cancelled.');
    } catch (\RuntimeException $e) {
      return $this->error($e->getMessage());
    }
  }

  // ── Private helpers ───────────────────────────────────────────────────

  private function findDispute(Request $request, string $id): ?Dispute
  {
    return $request->user()->disputes()->find($id);
  }

  private function formatDispute(Dispute $dispute, bool $detailed = false): array
  {
    $base = [
      'id'              => $dispute->id,
      'dispute_number'  => $dispute->dispute_number,
      'status'          => $dispute->status,
      'reason'          => $dispute->reason,
      'amount_disputed' => $dispute->amount_disputed,
      'amount_formatted' => '₦' . number_format(($dispute->amount_disputed ?? 0) / 100, 2),
      'refund_amount'   => $dispute->refund_amount,
      'refund_formatted' => $dispute->refund_amount
        ? '₦' . number_format($dispute->refund_amount / 100, 2)
        : null,
      'opened_at'       => $dispute->opened_at,
      'resolved_at'     => $dispute->resolved_at,
      'execution'       => $dispute->execution ? [
        'id'           => $dispute->execution->id,
        'total_amount' => $dispute->execution->total_amount,
        'completed_at' => $dispute->execution->completed_at,
      ] : null,
    ];

    if ($detailed) {
      $base['description']   = $dispute->description;
      $base['resolution_note'] = $dispute->resolution_note;
      $base['timeline']      = $dispute->meta['timeline'] ?? [];
    }

    return $base;
  }
}
