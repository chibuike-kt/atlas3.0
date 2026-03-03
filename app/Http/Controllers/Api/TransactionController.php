<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends BaseApiController
{
  /**
   * GET /api/transactions
   * Paginated transaction list with filters.
   */
  public function index(Request $request): JsonResponse
  {
    $query = $request->user()->transactions()
      ->with('connectedAccount')
      ->orderByDesc('transaction_date')
      ->orderByDesc('created_at');

    // Filters
    if ($request->filled('account_id')) {
      $query->where('connected_account_id', $request->account_id);
    }

    if ($request->filled('type')) {
      $query->where('type', $request->type);
    }

    if ($request->filled('category')) {
      $query->where('category', $request->category);
    }

    if ($request->filled('from')) {
      $query->where('transaction_date', '>=', $request->from);
    }

    if ($request->filled('to')) {
      $query->where('transaction_date', '<=', $request->to);
    }

    if ($request->filled('search')) {
      $search = $request->search;
      $query->where(function ($q) use ($search) {
        $q->where('narration', 'like', "%{$search}%")
          ->orWhere('description', 'like', "%{$search}%")
          ->orWhere('counterparty_name', 'like', "%{$search}%");
      });
    }

    if ($request->boolean('salary_only')) {
      $query->where('is_salary', true);
    }

    $paginator = $query->paginate($request->input('per_page', 20));

    return $this->paginated(
      $paginator->through(fn($t) => $this->formatTransaction($t)),
      'Transactions retrieved.'
    );
  }

  /**
   * GET /api/transactions/{id}
   */
  public function show(Request $request, string $id): JsonResponse
  {
    $transaction = $request->user()
      ->transactions()
      ->with('connectedAccount')
      ->find($id);

    if (! $transaction) {
      return $this->notFound('Transaction not found.');
    }

    return $this->success($this->formatTransaction($transaction, true));
  }

  /**
   * GET /api/transactions/summary
   * Spend summary by category for a given period.
   */
  public function summary(Request $request): JsonResponse
  {
    $from = $request->input('from', now()->startOfMonth()->toDateString());
    $to   = $request->input('to',   now()->toDateString());

    $transactions = $request->user()->transactions()
      ->debits()
      ->whereBetween('transaction_date', [$from, $to])
      ->selectRaw('category, SUM(amount) as total, COUNT(*) as count')
      ->groupBy('category')
      ->orderByDesc('total')
      ->get();

    $totalSpend = $transactions->sum('total');

    $categories = $transactions->map(fn($row) => [
      'category'   => $row->category ?? 'uncategorised',
      'total'      => $row->total,
      'formatted'  => '₦' . number_format($row->total / 100, 2),
      'count'      => $row->count,
      'percentage' => $totalSpend > 0
        ? round(($row->total / $totalSpend) * 100, 1)
        : 0,
    ]);

    // Income summary
    $totalIncome = $request->user()->transactions()
      ->credits()
      ->whereBetween('transaction_date', [$from, $to])
      ->sum('amount');

    return $this->success([
      'period'          => ['from' => $from, 'to' => $to],
      'total_spend'     => $totalSpend,
      'total_spend_formatted' => '₦' . number_format($totalSpend / 100, 2),
      'total_income'    => $totalIncome,
      'total_income_formatted' => '₦' . number_format($totalIncome / 100, 2),
      'net'             => $totalIncome - $totalSpend,
      'net_formatted'   => '₦' . number_format(($totalIncome - $totalSpend) / 100, 2),
      'by_category'     => $categories,
    ], 'Transaction summary retrieved.');
  }

  // ── Private helpers ───────────────────────────────────────────────────

  private function formatTransaction(Transaction $transaction, bool $detailed = false): array
  {
    $base = [
      'id'                 => $transaction->id,
      'type'               => $transaction->type,
      'amount'             => $transaction->amount,
      'amount_formatted'   => $transaction->amount_formatted,
      'currency'           => $transaction->currency,
      'description'        => $transaction->description ?? $transaction->narration,
      'category'           => $transaction->category,
      'sub_category'       => $transaction->sub_category,
      'is_salary'          => $transaction->is_salary,
      'is_family_transfer' => $transaction->is_family_transfer,
      'is_ajo'             => $transaction->is_ajo,
      'is_atlas_execution' => $transaction->is_atlas_execution,
      'transaction_date'   => $transaction->transaction_date,
      'account'            => $transaction->connectedAccount ? [
        'id'          => $transaction->connectedAccount->id,
        'institution' => $transaction->connectedAccount->institution,
        'number'      => $transaction->connectedAccount->masked_account_number,
      ] : null,
    ];

    if ($detailed) {
      $base['narration']            = $transaction->narration;
      $base['reference']            = $transaction->reference;
      $base['balance_after']        = $transaction->balance_after;
      $base['counterparty_name']    = $transaction->counterparty_name;
      $base['counterparty_account'] = $transaction->counterparty_account;
      $base['counterparty_bank']    = $transaction->counterparty_bank;
      $base['confidence_score']     = $transaction->confidence_score;
      $base['processed_at']         = $transaction->processed_at;
    }

    return $base;
  }
}
