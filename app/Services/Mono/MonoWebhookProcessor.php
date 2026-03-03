<?php

namespace App\Services\Mono;

use App\Models\ConnectedAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Enums\TransactionType;
use App\Services\Financial\TransactionCategorizerService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MonoWebhookProcessor
{
  public function __construct(
    private readonly TransactionCategorizerService $categorizer
  ) {}

  /**
   * Route incoming webhook events to the correct handler.
   */
  public function process(array $payload): void
  {
    $event = $payload['event'] ?? 'unknown';

    Log::info('Processing Mono webhook', ['event' => $event]);

    match ($event) {
      'mono.events.account_connected'   => $this->handleAccountConnected($payload),
      'mono.events.account_updated'     => $this->handleAccountUpdated($payload),
      'mono.events.transactions_updated' => $this->handleTransactionsUpdated($payload),
      'mono.events.account_reauthorised' => $this->handleAccountReauthorised($payload),
      default                           => Log::info('Unhandled Mono event', ['event' => $event]),
    };
  }

  // ── Event handlers ────────────────────────────────────────────────────

  private function handleAccountConnected(array $payload): void
  {
    $accountId = $payload['data']['account']['_id'] ?? null;

    if (! $accountId) {
      return;
    }

    $account = ConnectedAccount::where('mono_account_id', $accountId)->first();

    if ($account) {
      $account->update(['is_active' => true, 'last_synced_at' => now()]);
    }
  }

  private function handleAccountUpdated(array $payload): void
  {
    $accountId = $payload['data']['account'] ?? null;

    if (! $accountId) {
      return;
    }

    $account = ConnectedAccount::where('mono_account_id', $accountId)->first();

    if (! $account) {
      return;
    }

    // Update balance from payload if available
    $balance = $payload['data']['balance'] ?? null;

    if ($balance !== null) {
      $account->update([
        'balance'        => (int) ($balance * 100), // Convert to kobo
        'last_synced_at' => now(),
      ]);
    }
  }

  private function handleTransactionsUpdated(array $payload): void
  {
    $accountId = $payload['data']['account'] ?? null;

    if (! $accountId) {
      return;
    }

    $account = ConnectedAccount::where('mono_account_id', $accountId)->first();

    if (! $account) {
      return;
    }

    // New transactions are in the payload
    $transactions = $payload['data']['transactions'] ?? [];

    foreach ($transactions as $txData) {
      $this->upsertTransaction($account, $txData);
    }

    $account->update(['last_synced_at' => now()]);
  }

  private function handleAccountReauthorised(array $payload): void
  {
    $accountId = $payload['data']['account']['_id'] ?? null;

    if (! $accountId) {
      return;
    }

    ConnectedAccount::where('mono_account_id', $accountId)
      ->update(['is_active' => true, 'last_synced_at' => now()]);
  }

  // ── Transaction upserting ─────────────────────────────────────────────

  public function upsertTransaction(ConnectedAccount $account, array $txData): ?Transaction
  {
    $monoId = $txData['_id'] ?? null;

    // Skip if already imported
    if ($monoId && Transaction::where('mono_transaction_id', $monoId)->exists()) {
      return null;
    }

    $amount = abs((int) (($txData['amount'] ?? 0) * 100)); // Convert to kobo
    $type   = ($txData['type'] ?? 'debit') === 'credit'
      ? TransactionType::Credit
      : TransactionType::Debit;

    $narration = $txData['narration'] ?? $txData['description'] ?? '';

    // Run categoriser
    $categorisation = $this->categorizer->categorise($narration, $type, $amount);

    $transaction = Transaction::create([
      'id'                   => Str::uuid(),
      'user_id'              => $account->user_id,
      'connected_account_id' => $account->id,
      'mono_transaction_id'  => $monoId,
      'type'                 => $type,
      'amount'               => $amount,
      'balance_after'        => isset($txData['balance'])
        ? (int) ($txData['balance'] * 100)
        : null,
      'currency'             => $txData['currency'] ?? 'NGN',
      'narration'            => $narration,
      'description'          => $categorisation['description'] ?? $narration,
      'category'             => $categorisation['category'],
      'sub_category'         => $categorisation['sub_category'],
      'is_salary'            => $categorisation['is_salary'],
      'is_family_transfer'   => $categorisation['is_family_transfer'],
      'is_ajo'               => $categorisation['is_ajo'],
      'is_bill_payment'      => $categorisation['is_bill_payment'],
      'is_atlas_execution'   => $categorisation['is_atlas_execution'],
      'confidence_score'     => $categorisation['confidence'],
      'counterparty_name'    => $txData['counterparty']['name'] ?? null,
      'counterparty_account' => $txData['counterparty']['account_number'] ?? null,
      'counterparty_bank'    => $txData['counterparty']['bank_code'] ?? null,
      'transaction_date'     => $txData['date']
        ? \Carbon\Carbon::parse($txData['date'])->toDateString()
        : now()->toDateString(),
      'processed_at'         => now(),
    ]);

    return $transaction;
  }
}
