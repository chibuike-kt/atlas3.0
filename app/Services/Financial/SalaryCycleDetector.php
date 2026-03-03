<?php

namespace App\Services\Financial;

use App\Models\ConnectedAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Enums\TransactionType;
use Illuminate\Support\Collection;

class SalaryCycleDetector
{
  private const MIN_MONTHS         = 2;
  private const CONSISTENCY_WINDOW = 3;  // months to check
  private const DAY_TOLERANCE      = 5;  // days either side of detected salary day
  private const MIN_SALARY_KOBO    = 2000000; // N20,000 minimum

  /**
   * Analyse transaction history and return salary intelligence.
   */
  public function detect(User $user): array
  {
    $salaryTransactions = $user->transactions()
      ->credits()
      ->where('is_salary', true)
      ->where('amount', '>=', self::MIN_SALARY_KOBO)
      ->orderBy('transaction_date')
      ->get();

    // Fall back to heuristic detection if categoriser hasn't tagged salary yet
    if ($salaryTransactions->count() < self::MIN_MONTHS) {
      $salaryTransactions = $this->heuristicSalaryDetection($user);
    }

    if ($salaryTransactions->count() < self::MIN_MONTHS) {
      return $this->noSalaryResult();
    }

    return $this->analyseSalaryPattern($salaryTransactions);
  }

  // ── Private methods ───────────────────────────────────────────────────

  private function heuristicSalaryDetection(User $user): Collection
  {
    // Look for large credits arriving on similar days each month
    $credits = $user->transactions()
      ->credits()
      ->where('amount', '>=', self::MIN_SALARY_KOBO)
      ->where('transaction_date', '>=', now()->subMonths(6)->toDateString())
      ->orderBy('transaction_date')
      ->get();

    if ($credits->isEmpty()) {
      return collect();
    }

    // Group by month and find the largest credit per month
    $monthlyLargest = $credits->groupBy(function ($tx) {
      return $tx->transaction_date->format('Y-m');
    })->map(fn($group) => $group->sortByDesc('amount')->first());

    if ($monthlyLargest->count() < self::MIN_MONTHS) {
      return collect();
    }

    // Check if these largest credits land on similar days
    $days = $monthlyLargest->map(fn($tx) => $tx->transaction_date->day)->values();
    $avgDay = $days->average();
    $variance = $days->map(fn($d) => abs($d - $avgDay))->average();

    // If variance is low enough, treat as salary
    if ($variance <= self::DAY_TOLERANCE) {
      return $monthlyLargest->values();
    }

    return collect();
  }

  private function analyseSalaryPattern(Collection $transactions): array
  {
    $amounts = $transactions->pluck('amount');
    $days    = $transactions->map(fn($t) => $t->transaction_date->day);

    $avgAmount   = (int) $amounts->average();
    $avgDay      = (int) round($days->average());
    $lastSalary  = $transactions->last();

    // Consistency score — how regular is the salary day
    $dayVariance = $days->map(fn($d) => abs($d - $avgDay))->average();
    $consistency = max(0, min(100, 100 - ($dayVariance * 10)));

    // Amount variance — flag if salary changes a lot month to month
    $amountVariance = $amounts->count() > 1
      ? ($amounts->max() - $amounts->min()) / max($amounts->average(), 1) * 100
      : 0;

    // Try to detect employer name from narration
    $source = $this->detectSource($transactions);

    return [
      'salary_detected'          => true,
      'salary_day'               => $avgDay,
      'average_salary'           => $avgAmount,
      'last_salary_amount'       => $lastSalary->amount,
      'last_salary_date'         => $lastSalary->transaction_date->toDateString(),
      'salary_source'            => $source,
      'salary_consistency_score' => round($consistency, 2),
      'amount_variance_percent'  => round($amountVariance, 2),
      'months_detected'          => $transactions->count(),
    ];
  }

  private function detectSource(Collection $transactions): ?string
  {
    $narrations = $transactions->pluck('narration')->filter()->map('strtolower');

    $excludeWords = ['salary', 'salry', 'payment', 'pay', 'credit', 'transfer', 'from'];

    foreach ($narrations as $narration) {
      $words = explode(' ', $narration);
      $candidates = array_filter($words, function ($word) use ($excludeWords) {
        return strlen($word) > 3 && ! in_array(strtolower($word), $excludeWords);
      });

      if (! empty($candidates)) {
        return ucwords(implode(' ', array_slice($candidates, 0, 3)));
      }
    }

    return null;
  }

  private function noSalaryResult(): array
  {
    return [
      'salary_detected'          => false,
      'salary_day'               => null,
      'average_salary'           => null,
      'last_salary_amount'       => null,
      'last_salary_date'         => null,
      'salary_source'            => null,
      'salary_consistency_score' => null,
      'amount_variance_percent'  => null,
      'months_detected'          => 0,
    ];
  }
}
