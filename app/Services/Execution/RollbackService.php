<?php

namespace App\Services\Execution;

use App\Models\ExecutionStep;
use App\Models\RuleExecution;
use App\Services\Rails\BankTransferRail;
use App\Services\Rails\PiggyvestRail;
use App\Services\Rails\CowrywiseRail;
use Illuminate\Support\Facades\Log;

class RollbackService
{
    public function __construct(
        private readonly BankTransferRail $bankRail,
        private readonly PiggyvestRail    $piggyvestRail,
        private readonly CowrywiseRail    $cowrywiseRail
    ) {}

    /**
     * Rollback all reversible completed steps in an execution.
     * Called automatically when any step fails.
     */
    public function rollback(RuleExecution $execution): void
    {
        $rollbackableSteps = $execution->steps()
            ->rollbackable()
            ->orderByDesc('step_order') // Reverse order
            ->get();

        if ($rollbackableSteps->isEmpty()) {
            $execution->markFailed($execution->failure_reason ?? 'Execution failed with no rollbackable steps.');
            return;
        }

        Log::info('Starting rollback', [
            'execution_id' => $execution->id,
            'steps'        => $rollbackableSteps->count(),
        ]);

        $allRolledBack = true;

        foreach ($rollbackableSteps as $step) {
            try {
                $this->rollbackStep($step);
            } catch (\Throwable $e) {
                $allRolledBack = false;

                Log::error('Step rollback failed', [
                    'step_id' => $step->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        if ($allRolledBack) {
            $execution->markRolledBack();

            // Restore balance
            $execution->connectedAccount->creditBalance(
                $execution->total_debited ?? 0
            );

            Log::info('Rollback complete', ['execution_id' => $execution->id]);
        } else {
            Log::error('Partial rollback — manual review required', [
                'execution_id' => $execution->id,
            ]);
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function rollbackStep(ExecutionStep $step): void
    {
        $actionType = \App\Enums\ActionType::from($step->action_type);

        $reference = match ($actionType) {
            \App\Enums\ActionType::SendBank      => $this->bankRail->reverse($step),
            \App\Enums\ActionType::SavePiggvest => $this->piggyvestRail->reverse($step),
            \App\Enums\ActionType::SaveCowrywise => $this->cowrywiseRail->reverse($step),
            default                              => throw new \RuntimeException(
                "Action type {$step->action_type} is not reversible."
            ),
        };

        $step->markRolledBack($reference);
    }
}
