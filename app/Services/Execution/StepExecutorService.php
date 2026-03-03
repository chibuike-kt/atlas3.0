<?php

namespace App\Services\Execution;

use App\Enums\ActionType;
use App\Enums\AmountType;
use App\Enums\ExecutionStatus;
use App\Models\ConnectedAccount;
use App\Models\ExecutionStep;
use App\Models\RuleExecution;
use App\Services\Rails\BankTransferRail;
use App\Services\Rails\PiggyvestRail;
use App\Services\Rails\CowrywiseRail;
use App\Services\Rails\CryptoRail;
use App\Services\Rails\BillPaymentRail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class StepExecutorService
{
    public function __construct(
        private readonly BankTransferRail  $bankRail,
        private readonly PiggyvestRail     $piggyvestRail,
        private readonly CowrywiseRail     $cowrywiseRail,
        private readonly CryptoRail        $cryptoRail,
        private readonly BillPaymentRail   $billRail
    ) {}

    /**
     * Execute a single step and return the persisted ExecutionStep record.
     */
    public function execute(
        RuleExecution    $execution,
        array            $action,
        int              $amount,
        ConnectedAccount $account
    ): ExecutionStep {

        $step = ExecutionStep::create([
            'id'           => Str::uuid(),
            'execution_id' => $execution->id,
            'user_id'      => $execution->user_id,
            'step_order'   => $action['step_order'] ?? 1,
            'action_type'  => $action['action_type'],
            'label'        => $action['label'] ?? null,
            'amount'       => $amount,
            'currency'     => 'NGN',
            'amount_type'  => $action['amount_type'],
            'status'       => ExecutionStatus::Running,
            'config'       => $action['config'] ?? [],
        ]);

        try {
            $actionType = ActionType::from($action['action_type']);
            $result     = $this->routeToRail($actionType, $step, $account);

            $step->markCompleted(
                $result['reference'] ?? Str::uuid(),
                $result
            );

            Log::info('Step completed', [
                'step_id'    => $step->id,
                'action'     => $action['action_type'],
                'amount'     => $amount,
                'reference'  => $result['reference'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $step->markFailed($e->getMessage());

            Log::error('Step failed', [
                'step_id' => $step->id,
                'action'  => $action['action_type'],
                'error'   => $e->getMessage(),
            ]);
        }

        return $step->fresh();
    }

    // ── Rail routing ──────────────────────────────────────────────────────

    private function routeToRail(ActionType $actionType, ExecutionStep $step, ConnectedAccount $account): array
    {
        return match ($actionType) {
            ActionType::SendBank      => $this->bankRail->execute($step, $account),
            ActionType::SavePiggvest => $this->piggyvestRail->execute($step, $account),
            ActionType::SaveCowrywise => $this->cowrywiseRail->execute($step, $account),
            ActionType::ConvertCrypto => $this->cryptoRail->execute($step, $account),
            ActionType::PayBill       => $this->billRail->execute($step, $account),
        };
    }
}
