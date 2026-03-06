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
    match (true) {
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

  private function onExecutionCompleted(ExecutionCompleted $event): void
  {
    $e    = $event->execution;
    $rule = $e->rule;
    $user = $e->user;

    $amount = number_format($e->total_amount / 100, 2);

    $this->fcm->sendToUser(
      $user,
      'Rule executed',
      '"' . $rule->name . '" moved NGN ' . $amount . ' successfully.',
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
      '"' . $rule->name . '" could not execute. Tap to see why.',
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
    $amount = number_format($event->amount / 100, 2);

    $this->fcm->sendToUser(
      $event->user,
      'Salary arrived!',
      'NGN ' . $amount . ' just landed. Your rules are running.',
      [
        'type'   => 'salary_detected',
        'amount' => (string) $event->amount,
        'screen' => 'home',
      ]
    );
  }

  private function onLowBalance(LowBalanceDetected $event): void
  {
    $balance = number_format($event->balance / 100, 2);

    $this->fcm->sendToUser(
      $event->user,
      'Low balance',
      'Your balance is NGN ' . $balance . '. Watch your spending.',
      [
        'type'    => 'low_balance',
        'balance' => (string) $event->balance,
        'screen'  => 'home',
      ]
    );
  }

  private function onDisputeResolved(DisputeResolved $event): void
  {
    $d        = $event->dispute;
    $user     = $d->user;
    $refunded = $d->status === 'resolved_refund';

    if ($refunded) {
      $refundAmount = number_format(($d->refund_amount ?? 0) / 100, 2);
      $body = 'Dispute #' . $d->dispute_number . ' resolved. NGN ' . $refundAmount . ' refunded.';
      $title = 'Dispute resolved - refunded';
    } else {
      $body  = 'Dispute #' . $d->dispute_number . ' was reviewed. No action was taken.';
      $title = 'Dispute closed';
    }

    $this->fcm->sendToUser(
      $user,
      $title,
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
    $a           = $event->advance;
    $amount      = number_format($a->amount / 100, 2);
    $repayment   = number_format($a->repayment_amount / 100, 2);
    $salaryDay   = $a->expected_salary_day;

    $this->fcm->sendToUser(
      $a->user,
      'Advance disbursed',
      'NGN ' . $amount . ' added to your account. Repayment of NGN ' . $repayment . ' due on the ' . $salaryDay . 'th.',
      [
        'type'       => 'advance_disbursed',
        'advance_id' => $a->id,
        'screen'     => 'advance_detail',
      ]
    );
  }

  private function onAdvanceRepaid(AdvanceRepaid $event): void
  {
    $a         = $event->advance;
    $repayment = number_format($a->repayment_amount / 100, 2);

    $this->fcm->sendToUser(
      $a->user,
      'Advance repaid',
      'NGN ' . $repayment . ' advance repaid automatically. You are all clear.',
      [
        'type'       => 'advance_repaid',
        'advance_id' => $a->id,
        'screen'     => 'advance_detail',
      ]
    );
  }
}
