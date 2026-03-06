<?php

namespace App\Services;

use App\Models\Dispute;
use App\Models\RuleExecution;
use App\Models\User;
use App\Events\DisputeResolved;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Str;

class DisputeService
{
    public function __construct(private readonly LedgerService $ledgerService) {}

    /**
     * Open a new dispute against an execution.
     */
    public function open(User $user, RuleExecution $execution, array $data): Dispute
    {
        // Prevent duplicate disputes on the same execution
        if ($execution->is_disputed) {
            throw new \RuntimeException('A dispute has already been raised for this execution.');
        }

        // Only completed executions can be disputed
        if (! $execution->isCompleted()) {
            throw new \RuntimeException('Only completed executions can be disputed.');
        }

        // Must be raised within 30 days
        if ($execution->completed_at && $execution->completed_at->diffInDays(now()) > 30) {
            throw new \RuntimeException('Disputes must be raised within 30 days of execution.');
        }

        $dispute = Dispute::create([
            'id'           => Str::uuid(),
            'user_id'      => $user->id,
            'execution_id' => $execution->id,
            'reason'       => $data['reason'],
            'description'  => $data['description'] ?? null,
            'amount'       => $execution->total_amount,
            'currency'     => 'NGN',
            'status'       => 'open',
            'opened_at'    => now(),
        ]);

        // Flag the execution as disputed
        $execution->update(['is_disputed' => true]);

        return $dispute;
    }

    /**
     * Add evidence or a message to an open dispute.
     */
    public function addEvidence(Dispute $dispute, string $message): Dispute
    {
        if ($dispute->status === 'resolved') {
            throw new \RuntimeException('Cannot add evidence to a resolved dispute.');
        }

        $timeline   = $dispute->timeline ?? [];
        $timeline[] = [
            'type'    => 'user_message',
            'message' => $message,
            'at'      => now()->toISOString(),
        ];

        $dispute->update(['timeline' => $timeline]);

        return $dispute->fresh();
    }

    /**
     * Resolve a dispute — admin action.
     * Resolution types: refunded, rejected, partial_refund
     */
    public function resolve(Dispute $dispute, string $resolution, ?int $refundAmount = null, ?string $notes = null): Dispute
    {
        if ($dispute->status === 'resolved') {
            throw new \RuntimeException('Dispute is already resolved.');
        }

        $timeline   = $dispute->timeline ?? [];
        $timeline[] = [
            'type'       => 'resolution',
            'resolution' => $resolution,
            'notes'      => $notes,
            'at'         => now()->toISOString(),
        ];

        $dispute->update([
            'status'         => 'resolved',
            'resolution'     => $resolution,
            'resolution_notes'=> $notes,
            'refund_amount'  => $refundAmount,
            'timeline'       => $timeline,
            'resolved_at'    => now(),
        ]);

        // Process refund if applicable
        if (in_array($resolution, ['refunded', 'partial_refund']) && $refundAmount > 0) {
            $this->ledgerService->recordRefund(
                $dispute->execution,
                $refundAmount,
                "Dispute {$dispute->id} — {$resolution}"
            );
        }

        return tap($dispute->fresh(), function ($resolved) {
            DisputeResolved::dispatch($resolved);
        });
    }
     
    public function cancel(Dispute $dispute): Dispute
    {
        if ($dispute->status === 'resolved') {
            throw new \RuntimeException('Cannot cancel a resolved dispute.');
        }

        $dispute->update([
            'status'      => 'cancelled',
            'resolved_at' => now(),
        ]);

        // Un-flag the execution
        $dispute->execution->update(['is_disputed' => false]);

        return $dispute->fresh();
    }
}
