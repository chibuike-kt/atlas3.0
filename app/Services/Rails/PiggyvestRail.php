<?php

namespace App\Services\Rails;

use App\Models\ConnectedAccount;
use App\Models\ExecutionStep;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PiggyvestRail extends BaseRail
{
    public function execute(ExecutionStep $step, ConnectedAccount $account): array
    {
        $reference   = $this->generateReference('PGV');
        $amountNaira = $this->toNaira($step->amount);

        if (config('app.env') !== 'production') {
            Log::info('PiggyvestRail sandbox execution', [
                'reference' => $reference,
                'amount'    => $amountNaira,
            ]);

            return [
                'reference'    => $reference,
                'status'       => 'success',
                'amount'       => $amountNaira,
                'savings_type' => $step->config['savings_type'] ?? 'piggybank',
                'mode'         => 'sandbox',
            ];
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('atlas.piggyvest.api_key'),
            'Content-Type'  => 'application/json',
        ])
        ->post(config('atlas.piggyvest.base_url') . '/v1/savings/fund', [
            'amount'       => $amountNaira,
            'reference'    => $reference,
            'savings_type' => $step->config['savings_type'] ?? 'piggybank',
            'description'  => $step->label ?? 'Atlas savings rule',
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'PiggyVest transfer failed: ' . ($response->json('message') ?? $response->status())
            );
        }

        return [
            'reference' => $reference,
            'status'    => 'success',
            'amount'    => $amountNaira,
            'response'  => $response->json(),
        ];
    }

    public function reverse(ExecutionStep $step): string
    {
        $reference = $this->generateReference('PGV-REV');

        if (config('app.env') !== 'production') {
            Log::info('PiggyvestRail sandbox reversal', [
                'original' => $step->rail_reference,
                'reversal' => $reference,
            ]);

            return $reference;
        }

        // Production withdrawal/reversal from PiggyVest would go here
        return $reference;
    }
}
