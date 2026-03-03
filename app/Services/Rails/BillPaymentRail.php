<?php

namespace App\Services\Rails;

use App\Models\ConnectedAccount;
use App\Models\ExecutionStep;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BillPaymentRail extends BaseRail
{
    public function execute(ExecutionStep $step, ConnectedAccount $account): array
    {
        $reference   = $this->generateReference('BILL');
        $amountNaira = $this->toNaira($step->amount);
        $config      = $step->config ?? [];

        if (config('app.env') !== 'production') {
            Log::info('BillPaymentRail sandbox execution', [
                'reference'   => $reference,
                'amount'      => $amountNaira,
                'service_id'  => $config['service_id'] ?? 'mtn-data',
                'phone'       => $config['phone'] ?? 'sandbox',
            ]);

            return [
                'reference'  => $reference,
                'status'     => 'success',
                'amount'     => $amountNaira,
                'service_id' => $config['service_id'] ?? 'mtn-data',
                'mode'       => 'sandbox',
            ];
        }

        $response = Http::withHeaders([
            'api-key'    => config('atlas.vtpass.api_key'),
            'secret-key' => config('atlas.vtpass.secret_key'),
            'Content-Type' => 'application/json',
        ])
        ->post(config('atlas.vtpass.base_url') . '/pay', [
            'request_id'     => $reference,
            'serviceID'      => $config['service_id'],
            'amount'         => $amountNaira,
            'phone'          => $config['phone'] ?? null,
            'billersCode'    => $config['billers_code'] ?? null,
            'variation_code' => $config['variation_code'] ?? null,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'Bill payment failed: ' . ($response->json('response_description') ?? $response->status())
            );
        }

        $body = $response->json();

        if (($body['code'] ?? '') !== '000') {
            throw new \RuntimeException(
                'Bill payment rejected: ' . ($body['response_description'] ?? 'Unknown error')
            );
        }

        return [
            'reference'  => $reference,
            'status'     => 'success',
            'amount'     => $amountNaira,
            'token'      => $body['purchased_code'] ?? null,
            'response'   => $body,
        ];
    }
}
