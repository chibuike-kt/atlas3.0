<?php

namespace App\Services\Execution;

use App\Enums\ActionType;
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

    public function execute(
        RuleExecution    $execution,
        array            $action,
        int              $amount,
        ConnectedAccount $account
    ): ExecutionStep {

        $actionTypeString = is_string($action['action_type'])
            ? $action['action_type']
            : $action['action_type']->value;

        $amountTypeString = is_string($action['amount_type'])
            ? $action['amount_type']
            : $action['amount_type']->value;

        $step = ExecutionStep::create([
            'id'           => Str::uuid(),
            'execution_id' => $execution->id,
            'user_id'      => $execution->user_id,
            'step_order'   => $action['step_order'] ?? 1,
            'action_type'  => $actionTypeString,
            'label'        => $action['label'] ?? null,
            'amount'       => $amount,
            'currency'     => 'NGN',
            'amount_type'  => $amountTypeString,
            'status'       => ExecutionStatus::Running->value,
            'config'       => $action['config'] ?? [],
        ]);

        try {
            $actionType = ActionType::from($actionTypeString);
            $result     = $this->routeToRail($actionType, $step, $account);

            $step->markCompleted(
                $result['reference'] ?? Str::uuid(),
                $result
            );

            Log::info('Step completed', [
                'step_id'   => $step->id,
                'action'    => $actionTypeString,
                'amount'    => $amount,
                'reference' => $result['reference'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $step->markFailed($e->getMessage());

            Log::error('Step failed', [
                'step_id' => $step->id,
                'action'  => $actionTypeString,
                'error'   => $e->getMessage(),
            ]);
        }

        return $step->fresh();
    }

    private function routeToRail(ActionType $actionType, ExecutionStep $step, ConnectedAccount $account): array
    {
        return match ($actionType) {
            ActionType::SendBank      => $this->bankRail->execute($step, $account),
            ActionType::SavePiggvest  => $this->piggyvestRail->execute($step, $account),
            ActionType::SaveCowrywise => $this->cowrywiseRail->execute($step, $account),
            ActionType::ConvertCrypto => $this->cryptoRail->execute($step, $account),
            ActionType::PayBill       => $this->billRail->execute($step, $account),
        };
    }
}
