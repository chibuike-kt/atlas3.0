<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Dispute;
use App\Services\DisputeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDisputeController extends BaseApiController
{
  public function __construct(private readonly DisputeService $disputeService) {}

  /**
   * GET /api/admin/disputes
   */
  public function index(Request $request): JsonResponse
  {
    $query = Dispute::with(['user', 'execution'])
      ->orderByDesc('opened_at');

    if ($request->filled('status')) {
      $query->where('status', $request->status);
    }

    if ($request->filled('search')) {
      $s = $request->search;
      $query->whereHas(
        'user',
        fn($q) => $q
          ->where('full_name', 'like', "%{$s}%")
          ->orWhere('email', 'like', "%{$s}%")
      )
        ->orWhere('dispute_number', 'like', "%{$s}%");
    }

    $disputes = $query->paginate($request->input('per_page', 25));

    return $this->paginated(
      $disputes->through(fn($d) => $this->formatDispute($d)),
      'Disputes retrieved.'
    );
  }

  /**
   * GET /api/admin/disputes/{id}
   */
  public function show(string $id): JsonResponse
  {
    $dispute = Dispute::with(['user', 'execution.steps'])->find($id);

    if (! $dispute) {
      return $this->notFound('Dispute not found.');
    }

    return $this->success($this->formatDispute($dispute, true));
  }

  /**
   * POST /api/admin/disputes/{id}/review
   * Mark a dispute as under review.
   */
  public function review(string $id): JsonResponse
  {
    $dispute = Dispute::find($id);

    if (! $dispute) {
      return $this->notFound('Dispute not found.');
    }

    $dispute->update(['status' => 'under_review', 'reviewed_at' => now()]);

    return $this->success($this->formatDispute($dispute->fresh()), 'Dispute marked as under review.');
  }

  /**
   * POST /api/admin/disputes/{id}/resolve
   */
  public function resolve(Request $request, string $id): JsonResponse
  {
    $dispute = Dispute::with('execution')->find($id);

    if (! $dispute) {
      return $this->notFound('Dispute not found.');
    }

    $validated = $request->validate([
      'resolution'    => ['required', 'string', 'in:resolved_refund,resolved_no_action'],
      'notes'         => ['required', 'string', 'max:1000'],
      'refund_amount' => ['required_if:resolution,resolved_refund', 'nullable', 'integer', 'min:1'],
    ]);

    try {
      $dispute = $this->disputeService->resolve(
        $dispute,
        $validated['resolution'],
        $validated['refund_amount'] ?? null,
        $validated['notes']
      );

      $message = $validated['resolution'] === 'resolved_refund'
        ? 'Dispute resolved with refund of ₦' . number_format(($validated['refund_amount'] ?? 0) / 100, 2) . '.'
        : 'Dispute resolved with no action.';

      return $this->success($this->formatDispute($dispute), $message);
    } catch (\RuntimeException $e) {
      return $this->error($e->getMessage());
    }
  }

  // ── Private helpers ───────────────────────────────────────────────────

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
      'resolution_note' => $dispute->resolution_note,
      'opened_at'       => $dispute->opened_at,
      'resolved_at'     => $dispute->resolved_at,
      'user'            => $dispute->user ? [
        'id'       => $dispute->user->id,
        'name'     => $dispute->user->full_name,
        'email'    => $dispute->user->email,
      ] : null,
    ];

    if ($detailed) {
      $base['description'] = $dispute->description;
      $base['timeline']    = $dispute->meta['timeline'] ?? [];
      $base['execution']   = $dispute->execution ? [
        'id'           => $dispute->execution->id,
        'total_amount' => $dispute->execution->total_amount,
        'completed_at' => $dispute->execution->completed_at,
        'steps'        => $dispute->execution->steps?->map(fn($s) => [
          'action_type' => $s->action_type,
          'amount'      => $s->amount,
          'status'      => $s->status,
          'reference'   => $s->rail_reference,
        ])->toArray(),
      ] : null;
    }

    return $base;
  }
}
