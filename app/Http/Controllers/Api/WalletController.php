<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\AtlasWallet;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WalletController extends BaseApiController
{
  /**
   * GET /api/wallet
   * Returns all the user's Atlas wallets (one per network/token).
   */
  public function index(Request $request): JsonResponse
  {
    $wallets = $request->user()
      ->wallets()
      ->orderByDesc('balance')
      ->get()
      ->map(fn($w) => $this->formatWallet($w));

    $usdtRate = (float) SystemSetting::getValue('usdt_buy_rate', 1620);

    $totalUsdtBalance = $request->user()->wallets()->sum('balance');
    $totalNgnValue    = $totalUsdtBalance * $usdtRate;

    return $this->success([
      'wallets'            => $wallets,
      'total_usdt_balance' => round($totalUsdtBalance, 6),
      'total_ngn_value'    => (int) $totalNgnValue,
      'total_ngn_formatted' => '₦' . number_format($totalNgnValue / 100, 2),
      'usdt_rate'          => $usdtRate,
    ], 'Wallets retrieved.');
  }

  /**
   * GET /api/wallet/{network}
   * Returns a specific wallet, creating it if it doesn't exist.
   */
  public function show(Request $request, string $network): JsonResponse
  {
    $validNetworks = array_keys(config('atlas.crypto.networks', []));

    if (! in_array($network, $validNetworks)) {
      return $this->error('Invalid network. Valid: ' . implode(', ', $validNetworks));
    }

    $wallet = AtlasWallet::firstOrCreate(
      [
        'user_id' => $request->user()->id,
        'network' => $network,
        'token'   => 'USDT',
      ],
      [
        'id'              => Str::uuid(),
        'deposit_address' => $this->generateDepositAddress($network),
        'balance'         => 0,
      ]
    );

    $usdtRate  = (float) SystemSetting::getValue('usdt_buy_rate', 1620);
    $ngnValue  = $wallet->balance * $usdtRate;

    return $this->success([
      'wallet'          => $this->formatWallet($wallet),
      'ngn_value'       => (int) $ngnValue,
      'ngn_formatted'   => '₦' . number_format($ngnValue / 100, 2),
      'usdt_rate'       => $usdtRate,
      'network_info'    => config("atlas.crypto.networks.{$network}"),
    ]);
  }

  /**
   * POST /api/wallet/{network}/withdraw
   * Initiate a USDT withdrawal to an external wallet address.
   */
  public function withdraw(Request $request, string $network): JsonResponse
  {
    $validated = $request->validate([
      'amount'          => ['required', 'numeric', 'min:1'],
      'wallet_address'  => ['required', 'string', 'min:20', 'max:100'],
    ]);

    $wallet = $request->user()
      ->wallets()
      ->where('network', $network)
      ->first();

    if (! $wallet) {
      return $this->notFound('Wallet not found.');
    }

    $amount = (float) $validated['amount'];

    if ($wallet->balance < $amount) {
      return $this->error(
        "Insufficient USDT balance. Have {$wallet->balance} USDT, need {$amount} USDT."
      );
    }

    $networkConfig    = config("atlas.crypto.networks.{$network}", []);
    $withdrawalFeeUsd = $networkConfig['withdrawal_fee_usd'] ?? 1.00;

    if ($amount <= $withdrawalFeeUsd) {
      return $this->error(
        "Amount must be greater than the withdrawal fee of \${$withdrawalFeeUsd}."
      );
    }

    $netAmount = $amount - $withdrawalFeeUsd;

    if (config('app.env') !== 'production') {
      // Sandbox — simulate withdrawal
      $wallet->debit($amount);

      return $this->success([
        'reference'       => 'WDR-' . strtoupper(Str::random(10)),
        'amount'          => $amount,
        'fee_usd'         => $withdrawalFeeUsd,
        'net_amount'      => $netAmount,
        'wallet_address'  => $validated['wallet_address'],
        'network'         => $network,
        'status'          => 'processing',
        'estimated_time'  => $networkConfig['label'] ?? $network,
        'mode'            => 'sandbox',
      ], 'Withdrawal initiated. Processing time varies by network.');
    }

    return $this->error('Crypto withdrawals are not yet enabled in production.');
  }

  /**
   * GET /api/wallet/rates
   * Returns current NGN/USDT exchange rates.
   */
  public function rates(Request $request): JsonResponse
  {
    $buyRate  = (float) SystemSetting::getValue('usdt_buy_rate', 1620);
    $sellRate = (float) SystemSetting::getValue('usdt_sell_rate', 1600);
    $spread   = config('atlas.fees.crypto_fx_spread', 0.005);

    return $this->success([
      'usdt_buy_rate'    => $buyRate,
      'usdt_sell_rate'   => $sellRate,
      'spread_percent'   => $spread * 100,
      'buy_formatted'    => '₦' . number_format($buyRate, 2) . ' / USDT',
      'sell_formatted'   => '₦' . number_format($sellRate, 2) . ' / USDT',
      'last_updated'     => now()->toISOString(),
    ], 'Exchange rates retrieved.');
  }

  // ── Private helpers ───────────────────────────────────────────────────

  private function formatWallet(AtlasWallet $wallet): array
  {
    return [
      'id'              => $wallet->id,
      'network'         => $wallet->network,
      'token'           => $wallet->token,
      'balance'         => $wallet->balance,
      'balance_display' => number_format($wallet->balance, 6) . ' USDT',
      'deposit_address' => $wallet->deposit_address,
      'network_label'   => config("atlas.crypto.networks.{$wallet->network}.label", $wallet->network),
      'created_at'      => $wallet->created_at,
    ];
  }

  private function generateDepositAddress(string $network): string
  {
    return match ($network) {
      'trc20'   => 'T' . strtoupper(substr(md5(uniqid()), 0, 33)),
      'bep20',
      'erc20',
      'polygon',
      'arbitrum' => '0x' . strtolower(substr(md5(uniqid()), 0, 40)),
      'solana'  => strtoupper(substr(base64_encode(random_bytes(32)), 0, 44)),
      default   => strtoupper(substr(md5(uniqid()), 0, 42)),
    };
  }
}
