<?php

namespace App\Listeners;

use App\Events\AdvanceDisbursed;
use App\Events\AdvanceRepaid;
use App\Events\DisputeResolved;
use App\Events\ExecutionCompleted;
use App\Events\ExecutionFailed;
use App\Events\LowBalanceDetected;
use App\Events\SalaryDetected;
use App\Services\Notifications\FcmService;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendPushNotification implements ShouldQueue
{
    public string $queue = 'notifications';

    public function __construct(private readonly FcmService $fcm) {}

    public function handle(mixed $event): void
    {
        match(true) {
            $event instanceof ExecutionCompleted  => $this->onExecutionCompleted($event),
            $event instanceof ExecutionFailed     => $this->onExecutionFailed($event),
            $event instanceof SalaryDetected      => $this->onSalaryDetected($event),
            $event instanceof LowBalanceDetected  => $this->onLowBalance($event),
            $event instanceof DisputeResolved     => $this->onDisputeResolved($event),
            $event instanceof AdvanceDisbursed    => $this->onAdvanceDisbursed($event),
            $event instanceof AdvanceRepaid       => $this->onAdvanceRepaid($event),
            default                               => null,
        };
    }

    // ── Event handlers ────────────────────────────────────────────────────

    private function onExecutionCompleted(ExecutionCompleted $event): void
    {
        $e    = $event->execution;
        $rule = $e->rule;
        $user = $e->user;

        $this->fcm->sendToUser(
            $user,
            'Rule executed',
            ""{$rule->name}" moved ₦" . number_format($e->total_amount / 100, 2) . " successfully.",
            [
                'type'         => 'execution_completed',
                'execution_id' => $e->id,
                'rule_id'      => $rule->id,
                'screen'       => 'execution_detail',
            ]
        );
    }

    private function onExecutionFailed(ExecutionFailed $event): void
    {
        $e    = $event->execution;
        $rule = $e->rule;
        $user = $e->user;

        $this->fcm->sendToUser(
            $user,
            'Rule failed',
            ""{$rule->name}" could not execute. Tap to see why.",
            [
                'type'         => 'execution_failed',
                'execution_id' => $e->id,
                'rule_id'      => $rule->id,
                'screen'       => 'execution_detail',
            ]
        );
    }

    private function onSalaryDetected(SalaryDetected $event): void
    {
        $this->fcm->sendToUser(
            $event->user,
            'Salary arrived!',
            "₦" . number_format($event->amount / 100, 2) . " just landed. Your rules are running.",
            [
                'type'   => 'salary_detected',
                'amount' => (string) $event->amount,
                'screen' => 'home',
            ]
        );
    }

    private function onLowBalance(LowBalanceDetected $event): void
    {
        $this->fcm->sendToUser(
            $event->user,
            'Low balance',
            "Your balance is ₦" . number_format($event->balance / 100, 2) . ". Watch your spending.",
            [
                'type'    => 'low_balance',
                'balance' => (string) $event->balance,
                'screen'  => 'home',
            ]
        );
    }

    private function onDisputeResolved(DisputeResolved $event): void
    {
        $d       = $event->dispute;
        $user    = $d->user;
        $refunded = $d->status === 'resolved_refund';

        $body = $refunded
            ? "Your dispute #{$d->dispute_number} was resolved. ₦" . number_format($d->refund_amount / 100, 2) . " refunded."
            : "Your dispute #{$d->dispute_number} was reviewed. No action was taken.";

        $this->fcm->sendToUser(
            $user,
            $refunded ? 'Dispute resolved — refunded' : 'Dispute closed',
            $body,
            [
                'type'       => 'dispute_resolved',
                'dispute_id' => $d->id,
                'screen'     => 'dispute_detail',
            ]
        );
    }

    private function onAdvanceDisbursed(AdvanceDisbursed $event): void
    {
        $a    = $event->advance;
        $user = $a->user;

        $this->fcm->sendToUser(
            $user,
            'Advance disbursed',
            "₦" . number_format($a->amount / 100, 2) . " has been added to your account. Repayment of ₦" .
            number_format($a->repayment_amount / 100, 2) . " due on the {$a->expected_salary_day}th.",
            [
                'type'       => 'advance_disbursed',
                'advance_id' => $a->id,
                'screen'     => 'advance_detail',
            ]
        );
    }

    private function onAdvanceRepaid(AdvanceRepaid $event): void
    {
        $a    = $event->advance;
        $user = $a->user;

        $this->fcm->sendToUser(
            $user,
            'Advance repaid',
            "₦" . number_format($a->repayment_amount / 100, 2) . " advance repaid automatically. You're all clear.",
            [
                'type'       => 'advance_repaid',
                'advance_id' => $a->id,
                'screen'     => 'advance_detail',
            ]
        );
    }
}
