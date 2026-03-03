<?php

namespace App\Services\Execution;

use App\Enums\ExecutionStatus;
use App\Enums\TriggerType;
use App\Enums\AmountType;
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

    public function execute(Rule $rule, string $triggerType = 'schedule'): RuleExecution
    {
        $this->assertExecutable($rule);

        $account      = $rule->connectedAccount;
        $totalAmount  = $this->resolveTotalAmount($rule, $account);
        $fee          = $this->feeCalculator->calculate($rule);
        $totalDebited = $totalAmount + $fee;

        if (! $account->hasSufficientBalance($totalDebited)) {
            throw new \RuntimeException(
                "Insufficient balance. Need ₦" . number_format($totalDebited / 100, 2) .
                    ", have ₦" . number_format($account->balance / 100, 2) . "."
            );
        }

        $execution = RuleExecution::create([
            'id'                   => Str::uuid(),
            'rule_id'              => $rule->id,
            'user_id'              => $rule->user_id,
            'connected_account_id' => $account->id,
            'idempotency_key'      => $rule->id . ':' . now()->format('YmdHi'),
            'status'               => ExecutionStatus::Pending->value,
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
            Log::error('Execution failed at: ' . $e->getFile() . ':' . $e->getLine() . ' — ' . $e->getMessage());

            $execution->markFailed(
                $e->getMessage() . ' [' . basename($e->getFile()) . ':' . $e->getLine() . ']'
            );

            $this->rollbackService->rollback($execution);
        }

        return $execution->fresh(['steps', 'receipt']);
    }

    public function executeManual(Rule $rule, User $user): RuleExecution
    {
        if ($rule->user_id !== $user->id) {
            throw new \RuntimeException('You do not have permission to execute this rule.');
        }

        return $this->execute($rule, TriggerType::Manual->value);
    }

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
        $amountType = $rule->total_amount_type instanceof AmountType
            ? $rule->total_amount_type
            : AmountType::from((string) $rule->total_amount_type);

        return $amountType->resolveAmount(
            $rule->total_amount ?? 0,
            $account->balance
        );
    }

    private function runSteps(Rule $rule, RuleExecution $execution, ConnectedAccount $account, int $totalAmount): void
    {
        $actions         = collect($rule->actions)->sortBy('step_order');
        $remainingAmount = $totalAmount;

        foreach ($actions as $action) {
            $action = (array) $action;

            $stepAmount = $this->resolveStepAmount($action, $remainingAmount, $account->balance);

            $step = $this->stepExecutor->execute(
                $execution,
                $action,
                $stepAmount,
                $account
            );

            if ($step->status === ExecutionStatus::Completed) {
                $remainingAmount = max(0, $remainingAmount - $stepAmount);
                $execution->increment('steps_completed');
            } else {
                $execution->increment('steps_failed');
                throw new \RuntimeException(
                    "Step " . ($action['step_order'] ?? '?') . " failed: " . ($step->failure_reason ?? 'Unknown error')
                );
            }
        }
    }

    private function finalise(Rule $rule, RuleExecution $execution, ConnectedAccount $account, int $totalAmount, int $fee): void
    {
        $account->deductBalance($totalAmount + $fee);
        $execution->update(['balance_after' => $account->fresh()->balance]);
        $execution->markCompleted($totalAmount, $fee);

        if ($fee > 0) {
            $this->ledgerService->recordFee($execution, $fee);
        }

        $this->ledgerService->recordExecution($execution);
        $this->ledgerService->generateReceipt($execution, $rule);
        $rule->recordSuccess($totalAmount);
        $this->ruleService->advanceNextTrigger($rule);
    }

    private function resolveStepAmount(array $action, int $remainingAmount, int $balance): int
    {
        $amountTypeStr = is_string($action['amount_type'])
            ? $action['amount_type']
            : (string) $action['amount_type'];

        $amountType = AmountType::from($amountTypeStr);

        return match ($amountType) {
            AmountType::Fixed      => (int) $action['amount'],
            AmountType::Percentage => (int) round($balance * ($action['amount'] / 10000)),
            AmountType::Remainder  => $remainingAmount,
        };
    }
}
