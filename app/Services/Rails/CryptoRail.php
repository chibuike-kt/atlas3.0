<?php

namespace App\Services\Rails;

use App\Models\AtlasWallet;
use App\Models\ConnectedAccount;
use App\Models\ExecutionStep;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CryptoRail extends BaseRail
{
    public function execute(ExecutionStep $step, ConnectedAccount $account): array
    {
        $reference   = $this->generateReference('CRY');
        $amountNaira = $this->toNaira($step->amount);
        $network     = $step->config['network'] ?? config('atlas.crypto.default_network', 'trc20');
        $token       = $step->config['token'] ?? 'USDT';

        // Get current exchange rate
        $usdtBuyRate = (float) SystemSetting::getValue('usdt_buy_rate', 1620);
        $usdtAmount  = $amountNaira / $usdtBuyRate;

        if (config('app.env') !== 'production') {
            Log::info('CryptoRail sandbox execution', [
                'reference'   => $reference,
                'ngn_amount'  => $amountNaira,
                'usdt_amount' => $usdtAmount,
                'network'     => $network,
            ]);

            // Credit the user's Atlas wallet
            $wallet = AtlasWallet::firstOrCreate(
                ['user_id' => $account->user_id, 'network' => $network, 'token' => $token],
                ['id' => Str::uuid(), 'deposit_address' => $this->generateSandboxAddress($network)]
            );

            $wallet->credit($usdtAmount);

            return [
                'reference'      => $reference,
                'status'         => 'success',
                'ngn_amount'     => $amountNaira,
                'usdt_amount'    => round($usdtAmount, 6),
                'rate'           => $usdtBuyRate,
                'network'        => $network,
                'wallet_id'      => $wallet->id,
                'mode'           => 'sandbox',
            ];
        }

        // Production crypto conversion would integrate with a crypto provider
        throw new \RuntimeException('Crypto rail not yet configured for production.');
    }

    public function reverse(ExecutionStep $step): string
    {
        throw new \RuntimeException('Crypto conversions cannot be reversed automatically. Please contact support.');
    }

    private function generateSandboxAddress(string $network): string
    {
        return match($network) {
            'trc20'   => 'T' . strtoupper(substr(md5(uniqid()), 0, 33)),
            'bep20'   => '0x' . strtolower(substr(md5(uniqid()), 0, 40)),
            'erc20'   => '0x' . strtolower(substr(md5(uniqid()), 0, 40)),
            'polygon' => '0x' . strtolower(substr(md5(uniqid()), 0, 40)),
            default   => strtoupper(substr(md5(uniqid()), 0, 44)),
        };
    }
}
