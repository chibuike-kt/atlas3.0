<?php

namespace App\Services\Mono;

use App\Models\ConnectedAccount;
use App\Models\User;
use App\Services\Mono\MonoService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class AccountService
{
  public function __construct(
    private readonly MonoService $mono,
    private readonly MonoWebhookProcessor $webhookProcessor
  ) {}

  /**
   * Link a new bank account using a Mono auth code.
   */
  public function linkAccount(User $user, string $code): ConnectedAccount
  {
    // Exchange code for Mono account ID
    $authData  = $this->mono->exchangeAuthCode($code);
    $accountId = $authData['account_id'];

    // Prevent duplicate links
    if (ConnectedAccount::where('mono_account_id', $accountId)->exists()) {
      throw new \RuntimeException('This bank account is already linked to an Atlas account.');
    }

    // Fetch full account details from Mono
    $accountData = $this->mono->getAccount($accountId);
    $account     = $accountData['account'] ?? $accountData;

    $isPrimary = ! $user->connectedAccounts()->exists();

    $connected = ConnectedAccount::create([
      'id'               => Str::uuid(),
      'user_id'          => $user->id,
      'mono_account_id'  => $accountId,
      'institution'      => $account['institution']['name'] ?? 'Unknown Bank',
      'bank_code'        => $account['institution']['bankCode'] ?? null,
      'account_name'     => $account['name'] ?? $user->full_name,
      'account_number'   => $account['accountNumber'] ?? '',
      'account_type'     => strtolower($account['type'] ?? 'current'),
      'balance'          => (int) (($account['balance'] ?? 0) * 100), // kobo
      'currency'         => $account['currency'] ?? 'NGN',
      'is_primary'       => $isPrimary,
      'is_active'        => true,
      'last_synced_at'   => now(),
    ]);

    // Kick off background transaction sync
    dispatch(function () use ($connected) {
      $this->syncTransactions($connected);
    })->afterResponse();

    return $connected;
  }

  /**
   * Sync the latest balance for an account.
   */
  public function syncBalance(ConnectedAccount $account): ConnectedAccount
  {
    try {
      $data    = $this->mono->getAccountBalance($account->mono_account_id);
      $balance = (int) (($data['balance'] ?? 0) * 100);

      $account->update([
        'balance'        => $balance,
        'last_synced_at' => now(),
      ]);
    } catch (\Throwable $e) {
      Log::error('Balance sync failed', [
        'account_id' => $account->id,
        'error'      => $e->getMessage(),
      ]);
    }

    return $account->fresh();
  }

  /**
   * Pull and import all transactions for an account.
   */
  public function syncTransactions(ConnectedAccount $account, int $days = 90): int
  {
    try {
      $transactions = $this->mono->getAllTransactions($account->mono_account_id, $days);
      $imported     = 0;

      foreach ($transactions as $txData) {
        $tx = $this->webhookProcessor->upsertTransaction($account, $txData);
        if ($tx) {
          $imported++;
        }
      }

      $account->update(['last_synced_at' => now()]);

      Log::info('Transaction sync complete', [
        'account_id' => $account->id,
        'imported'   => $imported,
        'total'      => count($transactions),
      ]);

      return $imported;
    } catch (\Throwable $e) {
      Log::error('Transaction sync failed', [
        'account_id' => $account->id,
        'error'      => $e->getMessage(),
      ]);

      return 0;
    }
  }

  /**
   * Set an account as the user's primary account.
   */
  public function setPrimary(User $user, ConnectedAccount $account): ConnectedAccount
  {
    // Remove primary flag from all other accounts
    $user->connectedAccounts()->update(['is_primary' => false]);

    $account->update(['is_primary' => true]);

    return $account->fresh();
  }

  /**
   * Unlink (remove) a connected account.
   */
  public function unlinkAccount(ConnectedAccount $account): void
  {
    if ($account->is_primary) {
      // Promote the next account to primary if one exists
      $next = ConnectedAccount::where('user_id', $account->user_id)
        ->where('id', '!=', $account->id)
        ->active()
        ->first();

      if ($next) {
        $next->update(['is_primary' => true]);
      }
    }

    // Notify Mono
    $this->mono->unlinkAccount($account->mono_account_id);

    $account->delete();
  }
}
