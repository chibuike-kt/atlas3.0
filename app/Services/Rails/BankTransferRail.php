<?php

namespace App\Services\Rails;

use App\Models\ConnectedAccount;
use App\Models\ExecutionStep;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BankTransferRail extends BaseRail
{
    public function execute(ExecutionStep $step, ConnectedAccount $account): array
    {
        $config     = $step->config ?? [];
        $reference  = $this->generateReference('BNK');
        $amountNaira = $this->toNaira($step->amount);

        // In sandbox/dev mode — simulate success
        if (config('app.env') !== 'production') {
            Log::info('BankTransferRail sandbox execution', [
                'reference'   => $reference,
                'amount'      => $amountNaira,
                'recipient'   => $config['account_number'] ?? 'sandbox',
            ]);

            return [
                'reference'      => $reference,
                'status'         => 'success',
                'amount'         => $amountNaira,
                'recipient_name' => $config['account_name'] ?? 'Sandbox Recipient',
                'bank'           => $config['bank_code'] ?? '000',
                'mode'           => 'sandbox',
            ];
        }

        // Production — call Mono Pay API
        $response = Http::withHeaders([
            'mono-sec-key' => config('atlas.mono.secret_key'),
            'Content-Type' => 'application/json',
        ])
        ->post(config('atlas.mono.base_url') . '/v1/payments/initiate', [
            'amount'      => $step->amount, // kobo
            'type'        => 'onetime-debit',
            'description' => $step->label ?? 'Atlas rule execution',
            'reference'   => $reference,
            'account_id'  => $account->mono_account_id,
            'destination' => [
                'type'           => 'account_number',
                'account_number' => $config['account_number'],
                'bank_code'      => $config['bank_code'],
                'amount'         => $step->amount,
            ],
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'Bank transfer failed: ' . ($response->json('message') ?? $response->status())
            );
        }

        return [
            'reference'  => $reference,
            'status'     => 'success',
            'amount'     => $amountNaira,
            'response'   => $response->json(),
        ];
    }

    public function reverse(ExecutionStep $step): string
    {
        $reference = $this->generateReference('REV');

        if (config('app.env') !== 'production') {
            Log::info('BankTransferRail sandbox reversal', [
                'original_reference' => $step->rail_reference,
                'reversal_reference' => $reference,
            ]);

            return $reference;
        }

        // Production reversal logic would go here
        return $reference;
    }
}
