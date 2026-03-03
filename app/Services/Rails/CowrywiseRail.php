<?php

namespace App\Services\Rails;

use App\Models\ConnectedAccount;
use App\Models\ExecutionStep;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CowrywiseRail extends BaseRail
{
    public function execute(ExecutionStep $step, ConnectedAccount $account): array
    {
        $reference   = $this->generateReference('CWY');
        $amountNaira = $this->toNaira($step->amount);

        if (config('app.env') !== 'production') {
            Log::info('CowrywiseRail sandbox execution', [
                'reference' => $reference,
                'amount'    => $amountNaira,
            ]);

            return [
                'reference'   => $reference,
                'status'      => 'success',
                'amount'      => $amountNaira,
                'plan_type'   => $step->config['plan_type'] ?? 'savings',
                'mode'        => 'sandbox',
            ];
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('atlas.cowrywise.client_secret'),
            'Content-Type'  => 'application/json',
        ])
        ->post(config('atlas.cowrywise.base_url') . '/v1/investments/fund', [
            'amount'      => $amountNaira,
            'reference'   => $reference,
            'plan_type'   => $step->config['plan_type'] ?? 'savings',
            'description' => $step->label ?? 'Atlas investment rule',
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'Cowrywise transfer failed: ' . ($response->json('message') ?? $response->status())
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
        $reference = $this->generateReference('CWY-REV');

        if (config('app.env') !== 'production') {
            return $reference;
        }

        return $reference;
    }
}
