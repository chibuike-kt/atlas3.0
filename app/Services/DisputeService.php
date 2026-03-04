<?php

namespace App\Services;

use App\Models\Dispute;
use App\Models\RuleExecution;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Str;

class DisputeService
{
  public function __construct(private readonly LedgerService $ledgerService) {}

  public function open(User $user, RuleExecution $execution, array $data): Dispute
  {
    if ($execution->is_disputed) {
      throw new \RuntimeException('A dispute has already been raised for this execution.');
    }

    if (! $execution->isCompleted()) {
      throw new \RuntimeException('Only completed executions can be disputed.');
    }

    if ($execution->completed_at && $execution->completed_at->diffInDays(now()) > 30) {
      throw new \RuntimeException('Disputes must be raised within 30 days of execution.');
    }

    $dispute = Dispute::create([
      'id'              => Str::uuid(),
      'user_id'         => $user->id,
      'execution_id'    => $execution->id,
      'dispute_number'  => Dispute::generateDisputeNumber(),
      'reason'          => $data['reason'],
      'description'     => $data['description'] ?? 'No description provided.',
      'amount_disputed' => $execution->total_amount,
      'status'          => 'open',
      'opened_at'       => now(),
    ]);

    $execution->update(['is_disputed' => true]);

    return $dispute;
  }

  public function addEvidence(Dispute $dispute, string $message): Dispute
  {
    if ($dispute->status === 'closed' || $dispute->status === 'resolved_refund' || $dispute->status === 'resolved_no_action') {
      throw new \RuntimeException('Cannot add evidence to a resolved dispute.');
    }

    $meta             = $dispute->meta ?? [];
    $meta['timeline'] = $meta['timeline'] ?? [];
    $meta['timeline'][] = [
      'type'    => 'user_message',
      'message' => $message,
      'at'      => now()->toISOString(),
    ];

    $dispute->update(['meta' => $meta]);

    return $dispute->fresh();
  }

  public function resolve(Dispute $dispute, string $resolution, ?int $refundAmount = null, ?string $notes = null): Dispute
  {
    $dispute->update([
      'status'          => $resolution, // resolved_refund or resolved_no_action
      'resolution_note' => $notes,
      'refund_amount'   => $refundAmount,
      'resolved_at'     => now(),
    ]);

    if ($refundAmount > 0) {
      $this->ledgerService->recordRefund(
        $dispute->execution,
        $refundAmount,
        "Dispute {$dispute->dispute_number} resolved"
      );
    }

    return $dispute->fresh();
  }

  public function cancel(Dispute $dispute): Dispute
  {
    $dispute->update([
      'status'      => 'closed',
      'resolved_at' => now(),
    ]);

    $dispute->execution->update(['is_disputed' => false]);

    return $dispute->fresh();
  }
}
