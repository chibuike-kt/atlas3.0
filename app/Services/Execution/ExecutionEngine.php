<?php

namespace App\Services\Execution;

use App\Enums\ExecutionStatus;
use App\Enums\TriggerType;
use App\Models\ConnectedAccount;
use App\Models\Rule;
use App\Models\RuleExecution;
use App\Models\User;
use App\Services\Execution\FeeCalculatorService;
use App\Services\Execution\RollbackService;
use App\Services\Execution\StepExecutorService;
use App\Services\Ledger\LedgerService;
use App\Services\Rules\RuleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ExecutionEngine
{
    public function __construct(
        private readonly StepExecutorService  $stepExecutor,
        private readonly FeeCalculatorService $feeCalculator,
        private readonly RollbackService      $rollbackService,
        private readonly LedgerService        $ledgerService,
        private readonly RuleService          $ruleService
    ) {}

    /**
     * Execute a rule. Entry point for both scheduler and manual triggers.
     */
    public function execute(Rule $rule, string $triggerType = 'schedule'): RuleExecution
    {
        Log::info('Execution starting', ['rule_id' => $rule->id, 'trigger' => $triggerType]);

        // Pre-flight checks
        $this->assertExecutable($rule);

        $account = $rule->connectedAccount;

        // Calculate total amount needed
        $totalAmount = $this->resolveTotalAmount($rule, $account);
        $fee         = $this->feeCalculator->calculate($rule);
        $totalDebited = $totalAmount + $fee;

        // Check balance
        if (! $account->hasSufficientBalance($totalDebited)) {
            throw new \RuntimeException(
                "Insufficient balance. Need ₦" . number_format($totalDebited / 100, 2) .
                ", have ₦" . number_format($account->balance / 100, 2) . "."
            );
        }

        // Create the execution record
        $execution = RuleExecution::create([
            'id'                   => Str::uuid(),
            'rule_id'              => $rule->id,
            'user_id'              => $rule->user_id,
            'connected_account_id' => $account->id,
            'idempotency_key'      => $rule->id . ':' . now()->format('YmdHi'),
            'status'               => ExecutionStatus::Pending,
            'trigger_type'         => $triggerType,
            'total_amount'         => $totalAmount,
            'total_fee'            => $fee,
            'total_debited'        => $totalDebited,
            'steps_total'          => count($rule->actions),
            'steps_completed'      => 0,
            'steps_failed'         => 0,
            'balance_before'       => $account->balance,
        ]);

        $execution->markRunning();
        $rule->markTriggered();

        try {
            DB::transaction(function () use ($rule, $execution, $account, $totalAmount, $fee) {
                $this->runSteps($rule, $execution, $account, $totalAmount);
                $this->finalise($rule, $execution, $account, $totalAmount, $fee);
            });

        } catch (\Throwable $e) {
            Log::error('Execution failed', [
                'execution_id' => $execution->id,
                'error'        => $e->getMessage(),
            ]);

            $execution->markFailed($e->getMessage());
            $rule->recordFailure();

            // Attempt rollback of any completed steps
            $this->rollbackService->rollback($execution);
        }

        return $execution->fresh(['steps', 'receipt']);
    }

    /**
     * Manually trigger a rule execution (user-initiated, not scheduler).
     */
    public function executeManual(Rule $rule, User $user): RuleExecution
    {
        if ($rule->user_id !== $user->id) {
            throw new \RuntimeException('You do not have permission to execute this rule.');
        }

        return $this->execute($rule, TriggerType::Manual->value);
    }

    // ── Private methods ───────────────────────────────────────────────────

    private function assertExecutable(Rule $rule): void
    {
        if (! $rule->isExecutable()) {
            throw new \RuntimeException("Rule \"{$rule->name}\" is not active.");
        }

        if (! $rule->connectedAccount) {
            throw new \RuntimeException('The account linked to this rule no longer exists.');
        }

        if (! $rule->connectedAccount->is_active) {
            throw new \RuntimeException('The account linked to this rule is inactive.');
        }
    }

    private function resolveTotalAmount(Rule $rule, ConnectedAccount $account): int
    {
        $amountType = $rule->total_amount_type;

        return $amountType->resolveAmount(
            $rule->total_amount ?? 0,
            $account->balance
        );
    }

    private function runSteps(Rule $rule, RuleExecution $execution, ConnectedAccount $account, int $totalAmount): void
    {
        $actions          = collect($rule->actions)->sortBy('step_order');
        $remainingAmount  = $totalAmount;
        $completedSteps   = 0;

        foreach ($actions as $action) {
            $stepAmount = $this->resolveStepAmount($action, $remainingAmount, $account->balance);

            $step = $this->stepExecutor->execute(
                $execution,
                $action,
                $stepAmount,
                $account
            );

            if ($step->status === ExecutionStatus::Completed) {
                $completedSteps++;
                $remainingAmount = max(0, $remainingAmount - $stepAmount);

                $execution->increment('steps_completed');
            } else {
                $execution->increment('steps_failed');
                throw new \RuntimeException(
                    "Step {$action['step_order']} failed: " . ($step->failure_reason ?? 'Unknown error')
                );
            }
        }
    }

    private function finalise(Rule $rule, RuleExecution $execution, ConnectedAccount $account, int $totalAmount, int $fee): void
    {
        // Deduct balance
        $account->deductBalance($totalAmount + $fee);

        // Record balance after
        $execution->update(['balance_after' => $account->fresh()->balance]);

        // Mark execution complete
        $execution->markCompleted($totalAmount, $fee);

        // Record fee in fee ledger
        if ($fee > 0) {
            $this->ledgerService->recordFee($execution, $fee);
        }

        // Write ledger entries
        $this->ledgerService->recordExecution($execution);

        // Generate receipt
        $this->ledgerService->generateReceipt($execution, $rule);

        // Update rule stats
        $rule->recordSuccess($totalAmount);

        // Advance next trigger for scheduled rules
        $this->ruleService->advanceNextTrigger($rule);

        Log::info('Execution completed', [
            'execution_id' => $execution->id,
            'amount'       => $totalAmount,
            'fee'          => $fee,
        ]);
    }

    private function resolveStepAmount(array $action, int $remainingAmount, int $balance): int
    {
        $amountTypeString = is_string($action['amount_type'])
            ? $action['amount_type']
            : $action['amount_type']->value;

        $amountType = \App\Enums\AmountType::from($amountTypeString);

        return match ($amountType) {
            \App\Enums\AmountType::Fixed      => (int) $action['amount'],
            \App\Enums\AmountType::Percentage => (int) round($balance * ($action['amount'] / 10000)),
            \App\Enums\AmountType::Remainder  => $remainingAmount,
        };
    }
}
