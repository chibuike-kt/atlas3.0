<?php

namespace App\Services\Ledger;

use App\Models\FeeLedger;
use App\Models\LedgerEntry;
use App\Models\Receipt;
use App\Models\Rule;
use App\Models\RuleExecution;
use Illuminate\Support\Str;

class LedgerService
{
    public function recordExecution(RuleExecution $execution): void
    {
        $runningBalance = $execution->balance_after ?? 0;

        LedgerEntry::create([
            'id'             => Str::uuid(),
            'user_id'        => $execution->user_id,
            'execution_id'   => $execution->id,
            'entry_type'     => 'debit',
            'description'    => 'Rule execution debit',
            'amount'         => $execution->total_debited,
            'currency'       => 'NGN',
            'running_balance' => $runningBalance,
            'reference'      => LedgerEntry::generateReference(),
            'posted_at'      => now(),
        ]);

        foreach ($execution->steps()->completed()->get() as $step) {
            LedgerEntry::create([
                'id'             => Str::uuid(),
                'user_id'        => $execution->user_id,
                'execution_id'   => $execution->id,
                'step_id'        => $step->id,
                'entry_type'     => 'credit',
                'description'    => $step->label ?? $step->action_type,
                'amount'         => $step->amount,
                'currency'       => 'NGN',
                'running_balance' => $runningBalance,
                'reference'      => LedgerEntry::generateReference(),
                'posted_at'      => now(),
            ]);
        }
    }

    public function recordFee(RuleExecution $execution, int $fee): void
    {
        FeeLedger::create([
            'id'           => Str::uuid(),
            'user_id'      => $execution->user_id,
            'execution_id' => $execution->id,
            'fee_type'     => 'execution',
            'amount'       => $fee,
            'currency'     => 'NGN',
            'description'  => 'Atlas execution fee',
            'breakdown'    => [
                'steps' => $execution->steps_total,
                'rate'  => $fee,
            ],
            'charged_at'   => now(),
        ]);
    }

    public function generateReceipt(RuleExecution $execution, Rule $rule): Receipt
    {
        $stepsSummary = $execution->steps()->completed()->get()->map(fn($s) => [
            'step_order'  => $s->step_order,
            'action_type' => $s->action_type,
            'label'       => $s->label,
            'amount'      => $s->amount,
            'formatted'   => '₦' . number_format($s->amount / 100, 2),
            'reference'   => $s->rail_reference,
        ])->toArray();

        return Receipt::create([
            'id'             => Str::uuid(),
            'user_id'        => $execution->user_id,
            'execution_id'   => $execution->id,
            'receipt_number' => Receipt::generateReceiptNumber(),
            'rule_name'      => $rule->name,
            'total_amount'   => $execution->total_amount,
            'total_fee'      => $execution->total_fee,
            'total_debited'  => $execution->total_debited,
            'currency'       => 'NGN',
            'status'         => 'completed',
            'steps_summary'  => $stepsSummary,
            'issued_at'      => now(),
        ]);
    }

    public function recordRefund(RuleExecution $execution, int $refundAmount, string $reason): void
    {
        LedgerEntry::create([
            'id'             => Str::uuid(),
            'user_id'        => $execution->user_id,
            'execution_id'   => $execution->id,
            'entry_type'     => 'refund',
            'description'    => "Refund: {$reason}",
            'amount'         => $refundAmount,
            'currency'       => 'NGN',
            'running_balance' => $execution->connectedAccount->balance + $refundAmount,
            'reference'      => LedgerEntry::generateReference(),
            'posted_at'      => now(),
        ]);

        $execution->connectedAccount->creditBalance($refundAmount);
    }
}
