<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\ConnectedAccount;
use App\Services\Mono\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends BaseApiController
{
  public function __construct(private readonly AccountService $accountService) {}

  /**
   * GET /api/accounts
   */
  public function index(Request $request): JsonResponse
  {
    $accounts = $request->user()
      ->connectedAccounts()
      ->active()
      ->orderByDesc('is_primary')
      ->get()
      ->map(fn($a) => $this->formatAccount($a));

    return $this->success($accounts, 'Accounts retrieved.');
  }

  /**
   * POST /api/accounts/link
   */
  public function link(Request $request): JsonResponse
  {
    $request->validate([
      'code' => ['required', 'string'],
    ]);

    try {
      $account = $this->accountService->linkAccount(
        $request->user(),
        $request->input('code')
      );

      return $this->created(
        $this->formatAccount($account),
        'Bank account linked successfully. Syncing your transactions in the background.'
      );
    } catch (\RuntimeException $e) {
      return $this->error($e->getMessage());
    } catch (\Throwable $e) {
      return $this->serverError('Failed to link account. Please try again.');
    }
  }

  /**
   * GET /api/accounts/{id}
   */
  public function show(Request $request, string $id): JsonResponse
  {
    $account = $request->user()
      ->connectedAccounts()
      ->where('id', $id)
      ->first();

    if (! $account) {
      return $this->notFound('Account not found.');
    }

    return $this->success($this->formatAccount($account, true));
  }

  /**
   * POST /api/accounts/{id}/sync
   * Manually trigger a balance + transaction sync.
   */
  public function sync(Request $request, string $id): JsonResponse
  {
    $account = $request->user()
      ->connectedAccounts()
      ->where('id', $id)
      ->first();

    if (! $account) {
      return $this->notFound('Account not found.');
    }

    $account = $this->accountService->syncBalance($account);

    return $this->success(
      $this->formatAccount($account),
      'Account synced successfully.'
    );
  }

  /**
   * POST /api/accounts/{id}/set-primary
   */
  public function setPrimary(Request $request, string $id): JsonResponse
  {
    $account = $request->user()
      ->connectedAccounts()
      ->where('id', $id)
      ->active()
      ->first();

    if (! $account) {
      return $this->notFound('Account not found.');
    }

    $account = $this->accountService->setPrimary($request->user(), $account);

    return $this->success(
      $this->formatAccount($account),
      "{$account->institution} is now your primary account."
    );
  }

  /**
   * DELETE /api/accounts/{id}
   */
  public function unlink(Request $request, string $id): JsonResponse
  {
    $account = $request->user()
      ->connectedAccounts()
      ->where('id', $id)
      ->first();

    if (! $account) {
      return $this->notFound('Account not found.');
    }

    $activeRules = $account->rules()->active()->count();

    if ($activeRules > 0) {
      return $this->error(
        "This account has {$activeRules} active rule(s). Pause or delete them before unlinking."
      );
    }

    $this->accountService->unlinkAccount($account);

    return $this->noContent('Account unlinked successfully.');
  }

  // ── Private helpers ───────────────────────────────────────────────────

  private function formatAccount(ConnectedAccount $account, bool $detailed = false): array
  {
    $base = [
      'id'                   => $account->id,
      'institution'          => $account->institution,
      'bank_code'            => $account->bank_code,
      'account_name'         => $account->account_name,
      'account_number'       => $account->masked_account_number,
      'account_number_full'  => $account->account_number,
      'account_type'         => $account->account_type,
      'balance'              => $account->balance,
      'balance_formatted'    => $account->balance_formatted,
      'currency'             => $account->currency,
      'is_primary'           => $account->is_primary,
      'is_active'            => $account->is_active,
      'last_synced_at'       => $account->last_synced_at,
    ];

    if ($detailed) {
      $base['rules_count']      = $account->rules()->active()->count();
      $base['executions_count'] = $account->executions()->count();
    }

    return $base;
  }
}
