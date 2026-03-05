<?php

namespace App\Services\Finance;

use App\Models\FinancialProfile;
use App\Models\LedgerEntry;
use App\Models\SalaryAdvance;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Execution\FeeCalculatorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SalaryAdvanceService
{
  /**
   * Check if a user is eligible for a salary advance and return
   * the maximum amount they can request.
   */
  public function checkEligibility(User $user): array
  {
    $profile = $user->financialProfile;
    $account = $user->primaryAccount;

    // Base disqualifiers
    if (! $account) {
      return $this->ineligible('No connected bank account found.');
    }

    if (! $profile || ! $profile->salary_detected) {
      return $this->ineligible('Atlas needs at least 2 months of salary history to offer advances.');
    }

    if ($profile->salary_consistency_score < 60) {
      return $this->ineligible('Your salary pattern is too irregular for an advance right now.');
    }

    if ($profile->months_detected < 2) {
      return $this->ineligible('Atlas needs at least 2 months of salary history to offer advances.');
    }

    // Check for existing active advance
    $activeAdvance = $user->salaryAdvances()
      ->whereIn('status', ['pending', 'disbursed'])
      ->first();

    if ($activeAdvance) {
      return $this->ineligible('You already have an active advance of ₦' .
        number_format($activeAdvance->amount / 100, 2) . '. Repay it first.');
    }

    // Check repayment history — max 1 default ever
    $defaultCount = $user->salaryAdvances()
      ->where('status', 'defaulted')
      ->count();

    if ($defaultCount > 0) {
      return $this->ineligible('Previous advance was not repaid. Advances are currently unavailable.');
    }

    // Must be within advance window (last 7 days before salary day)
    $salaryDay   = $profile->salary_day;
    $today       = now()->day;
    $daysInMonth = now()->daysInMonth;
    $windowStart = $salaryDay - 7;

    // Check if salary already arrived this month
    $salaryArrived = $user->transactions()
      ->credits()
      ->where('is_salary', true)
      ->thisMonth()
      ->exists();

    if ($salaryArrived) {
      return $this->ineligible('Your salary has already arrived this month.');
    }

    if ($today < $windowStart || $today >= $salaryDay) {
      $daysUntilWindow = $windowStart - $today;

      if ($daysUntilWindow > 0) {
        return $this->ineligible(
          "Advances open {$daysUntilWindow} day(s) before your salary day (the {$salaryDay}th)."
        );
      }
    }

    // Calculate maximum advance amount
    $avgSalary      = $profile->average_salary ?? 0;
    $maxPercent     = (int) SystemSetting::getValue('advance_max_percent', 50);
    $maxAmount      = (int) ($avgSalary * ($maxPercent / 100));
    $feeRate        = (float) config('atlas.fees.salary_advance_rate', 0.03);
    $feeAmount      = (int) round($maxAmount * $feeRate);
    $netAmount      = $maxAmount - $feeAmount;

    return [
      'eligible'           => true,
      'reason'             => null,
      'max_amount'         => $maxAmount,
      'max_amount_formatted' => '₦' . number_format($maxAmount / 100, 2),
      'fee_rate_percent'   => $feeRate * 100,
      'fee_amount'         => $feeAmount,
      'fee_formatted'      => '₦' . number_format($feeAmount / 100, 2),
      'net_amount'         => $netAmount,
      'net_formatted'      => '₦' . number_format($netAmount / 100, 2),
      'salary_day'         => $salaryDay,
      'avg_salary'         => $avgSalary,
      'repayment_note'     => "The full amount will be recovered automatically when your salary arrives on the {$salaryDay}th.",
    ];
  }

  /**
   * Request a salary advance.
   */
  public function request(User $user, int $amount): SalaryAdvance
  {
    $eligibility = $this->checkEligibility($user);

    if (! $eligibility['eligible']) {
      throw new \RuntimeException($eligibility['reason']);
    }

    if ($amount > $eligibility['max_amount']) {
      throw new \RuntimeException(
        'Requested amount exceeds your maximum of ₦' .
          number_format($eligibility['max_amount'] / 100, 2) . '.'
      );
    }

    if ($amount < 1000000) { // Minimum ₦10,000
      throw new \RuntimeException('Minimum advance amount is ₦10,000.');
    }

    $feeRate   = (float) config('atlas.fees.salary_advance_rate', 0.03);
    $feeAmount = (int) round($amount * $feeRate);
    $repayment = $amount + $feeAmount;
    $profile   = $user->financialProfile;

    return DB::transaction(function () use ($user, $amount, $feeAmount, $repayment, $profile) {
      $advance = SalaryAdvance::create([
        'id'                  => Str::uuid(),
        'user_id'             => $user->id,
        'connected_account_id' => $user->primaryAccount->id,
        'amount'              => $amount,
        'fee'                 => $feeAmount,
        'repayment_amount'    => $repayment,
        'status'              => 'pending',
        'expected_salary_day' => $profile->salary_day,
        'due_date'            => $this->calculateDueDate($profile->salary_day),
        'requested_at'        => now(),
      ]);

      // Disburse immediately in sandbox
      if (config('app.env') !== 'production') {
        $this->disburse($advance);
      }

      return $advance->fresh();
    });
  }

  /**
   * Disburse an advance — credit the user's account.
   */
  public function disburse(SalaryAdvance $advance): void
  {
    $account = $advance->connectedAccount;

    DB::transaction(function () use ($advance, $account) {
      // Credit the account balance
      $account->creditBalance($advance->amount);

      // Write ledger entry
      LedgerEntry::create([
        'id'             => Str::uuid(),
        'user_id'        => $advance->user_id,
        'entry_type'     => 'credit',
        'description'    => 'Atlas Salary Advance disbursement',
        'amount'         => $advance->amount,
        'currency'       => 'NGN',
        'running_balance' => $account->fresh()->balance,
        'reference'      => 'ADV-' . strtoupper(substr($advance->id, 0, 8)),
        'posted_at'      => now(),
      ]);

      $advance->update([
        'status'       => 'disbursed',
        'disbursed_at' => now(),
      ]);
    });

    Log::info('Salary advance disbursed', [
      'advance_id' => $advance->id,
      'amount'     => $advance->amount,
      'user_id'    => $advance->user_id,
    ]);
  }

  /**
   * Attempt automatic repayment when salary arrives.
   * Called by MonoWebhookProcessor when a salary credit is detected.
   */
  public function attemptAutoRepayment(User $user, int $salaryAmount): bool
  {
    $advance = $user->salaryAdvances()
      ->where('status', 'disbursed')
      ->orderBy('disbursed_at')
      ->first();

    if (! $advance) {
      return false;
    }

    $account = $user->primaryAccount;

    if (! $account->hasSufficientBalance($advance->repayment_amount)) {
      Log::warning('Advance repayment failed — insufficient balance', [
        'advance_id'       => $advance->id,
        'repayment_amount' => $advance->repayment_amount,
        'balance'          => $account->balance,
      ]);

      return false;
    }

    DB::transaction(function () use ($advance, $account) {
      // Deduct repayment from account
      $account->deductBalance($advance->repayment_amount);

      // Write ledger entry
      LedgerEntry::create([
        'id'             => Str::uuid(),
        'user_id'        => $advance->user_id,
        'entry_type'     => 'debit',
        'description'    => 'Atlas Salary Advance repayment',
        'amount'         => $advance->repayment_amount,
        'currency'       => 'NGN',
        'running_balance' => $account->fresh()->balance,
        'reference'      => 'REP-' . strtoupper(substr($advance->id, 0, 8)),
        'posted_at'      => now(),
      ]);

      $advance->update([
        'status'      => 'repaid',
        'repaid_at'   => now(),
        'repaid_amount' => $advance->repayment_amount,
      ]);
    });

    Log::info('Salary advance repaid automatically', [
      'advance_id'       => $advance->id,
      'repayment_amount' => $advance->repayment_amount,
    ]);

    return true;
  }

  /**
   * Mark an advance as defaulted — called by scheduler if salary
   * doesn't arrive within 5 days after due date.
   */
  public function markDefaulted(SalaryAdvance $advance): void
  {
    $advance->update(['status' => 'defaulted']);

    Log::warning('Salary advance defaulted', [
      'advance_id' => $advance->id,
      'user_id'    => $advance->user_id,
      'amount'     => $advance->repayment_amount,
    ]);
  }

  // ── Private helpers ───────────────────────────────────────────────────

  private function ineligible(string $reason): array
  {
    return [
      'eligible'  => false,
      'reason'    => $reason,
      'max_amount' => 0,
    ];
  }

  private function calculateDueDate(int $salaryDay): \Carbon\Carbon
  {
    $now  = now();
    $due  = $now->copy()->setDay($salaryDay);

    if ($due->isPast()) {
      $due->addMonthNoOverflow();
    }

    // Add 3-day grace period after salary day
    return $due->addDays(3);
  }
}
