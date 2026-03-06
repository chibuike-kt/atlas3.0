<?php

namespace App\Services\Bills;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VTpassService
{
  private string $baseUrl;
  private string $apiKey;
  private string $secretKey;
  private string $publicKey;

  public function __construct()
  {
    $this->baseUrl   = config('atlas.vtpass.base_url');
    $this->apiKey    = config('atlas.vtpass.api_key');
    $this->secretKey = config('atlas.vtpass.secret_key');
    $this->publicKey = config('atlas.vtpass.public_key');
  }

  /**
   * Get available service variations (data plans, DSTV bouquets, etc.)
   */
  public function getVariations(string $serviceId): array
  {
    if (config('app.env') !== 'production') {
      return $this->sandboxVariations($serviceId);
    }

    $response = $this->get('/service-variations', ['serviceID' => $serviceId]);

    return $response['content']['varations'] ?? [];
  }

  /**
   * Verify a meter number or decoder number before payment.
   */
  public function verify(string $serviceId, string $billersCode, ?string $type = null): array
  {
    if (config('app.env') !== 'production') {
      return [
        'Customer_Name'   => 'Sandbox Customer',
        'Customer_Number' => $billersCode,
        'Customer_Type'   => $type ?? 'prepaid',
        'WTF'             => null,
      ];
    }

    $payload = [
      'serviceID'   => $serviceId,
      'billersCode' => $billersCode,
    ];

    if ($type) {
      $payload['type'] = $type;
    }

    return $this->post('/merchant-verify', $payload);
  }

  /**
   * Purchase airtime.
   */
  public function buyAirtime(string $network, string $phone, int $amountKobo, string $reference): array
  {
    $serviceIdMap = [
      'mtn'   => 'mtn',
      'airtel' => 'airtel',
      'glo'   => 'glo',
      '9mobile' => 'etisalat',
    ];

    $serviceId = $serviceIdMap[strtolower($network)] ?? $network;

    return $this->pay([
      'serviceID'  => $serviceId,
      'amount'     => $amountKobo / 100,
      'phone'      => $phone,
      'request_id' => $reference,
    ]);
  }

  /**
   * Purchase mobile data.
   */
  public function buyData(string $network, string $phone, string $variationCode, string $reference): array
  {
    $serviceIdMap = [
      'mtn'    => 'mtn-data',
      'airtel' => 'airtel-data',
      'glo'    => 'glo-data',
      '9mobile' => 'etisalat-data',
    ];

    $serviceId = $serviceIdMap[strtolower($network)] ?? $network . '-data';

    return $this->pay([
      'serviceID'      => $serviceId,
      'billersCode'    => $phone,
      'variation_code' => $variationCode,
      'phone'          => $phone,
      'request_id'     => $reference,
    ]);
  }

  /**
   * Pay electricity bill.
   */
  public function payElectricity(
    string $disco,
    string $meterNumber,
    string $meterType,
    int    $amountKobo,
    string $phone,
    string $reference
  ): array {
    $serviceIdMap = [
      'ikeja'    => 'ikeja-electric',
      'eko'      => 'eko-electric',
      'abuja'    => 'abuja-electric',
      'kano'     => 'kano-electric',
      'phed'     => 'phed',
      'enugu'    => 'enugu-electric',
      'ibadan'   => 'ibadan-electric',
      'jos'      => 'jos-electric',
      'kaduna'   => 'kaduna-electric',
    ];

    $serviceId = $serviceIdMap[strtolower($disco)] ?? $disco;

    return $this->pay([
      'serviceID'      => $serviceId,
      'billersCode'    => $meterNumber,
      'variation_code' => $meterType, // prepaid or postpaid
      'amount'         => $amountKobo / 100,
      'phone'          => $phone,
      'request_id'     => $reference,
    ]);
  }

  /**
   * Pay DSTV/GOtv/Startimes subscription.
   */
  public function payCable(
    string $provider,
    string $smartCardNumber,
    string $variationCode,
    string $phone,
    string $reference
  ): array {
    $serviceIdMap = [
      'dstv'      => 'dstv',
      'gotv'      => 'gotv',
      'startimes' => 'startimes',
    ];

    $serviceId = $serviceIdMap[strtolower($provider)] ?? $provider;

    return $this->pay([
      'serviceID'      => $serviceId,
      'billersCode'    => $smartCardNumber,
      'variation_code' => $variationCode,
      'phone'          => $phone,
      'request_id'     => $reference,
    ]);
  }

  // ── Private helpers ───────────────────────────────────────────────────

  private function pay(array $payload): array
  {
    if (config('app.env') !== 'production') {
      Log::info('VTpass sandbox pay', $payload);

      return [
        'code'                  => '000',
        'content'               => ['transactions' => ['status' => 'delivered']],
        'response_description'  => 'TRANSACTION SUCCESSFUL',
        'requestId'             => $payload['request_id'],
        'amount'                => $payload['amount'] ?? 0,
        'purchased_code'        => 'SANDBOX-TOKEN-' . strtoupper(substr(md5(uniqid()), 0, 10)),
      ];
    }

    return $this->post('/pay', $payload);
  }

  private function post(string $endpoint, array $payload): array
  {
    $response = Http::withHeaders([
      'api-key'      => $this->apiKey,
      'secret-key'   => $this->secretKey,
      'Content-Type' => 'application/json',
    ])
      ->timeout(30)
      ->post($this->baseUrl . $endpoint, $payload);

    if (! $response->successful()) {
      throw new \RuntimeException(
        'VTpass request failed: ' . ($response->json('response_description') ?? $response->status())
      );
    }

    $body = $response->json();

    if (isset($body['code']) && $body['code'] !== '000') {
      throw new \RuntimeException(
        'Bill payment failed: ' . ($body['response_description'] ?? 'Unknown error')
      );
    }

    return $body;
  }

  private function get(string $endpoint, array $params = []): array
  {
    $response = Http::withHeaders([
      'api-key'      => $this->apiKey,
      'public-key'   => $this->publicKey,
    ])
      ->timeout(30)
      ->get($this->baseUrl . $endpoint, $params);

    return $response->json() ?? [];
  }

  private function sandboxVariations(string $serviceId): array
  {
    $variations = [
      'mtn-data' => [
        ['name' => '500MB - 1 Day',   'variation_code' => 'mtn-500mb-1day',  'variation_amount' => '300',  'fixedPrice' => 'Yes'],
        ['name' => '1GB - 1 Day',     'variation_code' => 'mtn-1gb-1day',    'variation_amount' => '500',  'fixedPrice' => 'Yes'],
        ['name' => '2GB - 30 Days',   'variation_code' => 'mtn-2gb-30days',  'variation_amount' => '2000', 'fixedPrice' => 'Yes'],
        ['name' => '5GB - 30 Days',   'variation_code' => 'mtn-5gb-30days',  'variation_amount' => '3500', 'fixedPrice' => 'Yes'],
        ['name' => '10GB - 30 Days',  'variation_code' => 'mtn-10gb-30days', 'variation_amount' => '5000', 'fixedPrice' => 'Yes'],
      ],
      'dstv' => [
        ['name' => 'Padi',        'variation_code' => 'padi',        'variation_amount' => '2500',  'fixedPrice' => 'Yes'],
        ['name' => 'Yanga',       'variation_code' => 'yanga',       'variation_amount' => '3500',  'fixedPrice' => 'Yes'],
        ['name' => 'Confam',      'variation_code' => 'confam',      'variation_amount' => '6200',  'fixedPrice' => 'Yes'],
        ['name' => 'Compact',     'variation_code' => 'compact',     'variation_amount' => '10500', 'fixedPrice' => 'Yes'],
        ['name' => 'Compact Plus', 'variation_code' => 'compact-plus', 'variation_amount' => '16600', 'fixedPrice' => 'Yes'],
        ['name' => 'Premium',     'variation_code' => 'premium',     'variation_amount' => '29500', 'fixedPrice' => 'Yes'],
      ],
      'gotv' => [
        ['name' => 'GOtv Smallie', 'variation_code' => 'gotv-smallie', 'variation_amount' => '1575',  'fixedPrice' => 'Yes'],
        ['name' => 'GOtv Jinja',   'variation_code' => 'gotv-jinja',   'variation_amount' => '2715',  'fixedPrice' => 'Yes'],
        ['name' => 'GOtv Jolli',   'variation_code' => 'gotv-jolli',   'variation_amount' => '4110',  'fixedPrice' => 'Yes'],
        ['name' => 'GOtv Max',     'variation_code' => 'gotv-max',     'variation_amount' => '6000',  'fixedPrice' => 'Yes'],
        ['name' => 'GOtv Supa',    'variation_code' => 'gotv-supa',    'variation_amount' => '9600',  'fixedPrice' => 'Yes'],
      ],
    ];

    return $variations[$serviceId] ?? [];
  }
}
