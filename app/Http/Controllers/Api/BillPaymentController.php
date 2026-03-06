<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\BillPayment;
use App\Services\Bills\BillPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillPaymentController extends BaseApiController
{
  public function __construct(private readonly BillPaymentService $billService) {}

  /**
   * GET /api/bills/variations/{serviceId}
   * Get available plans/bouquets for a service.
   */
  public function variations(Request $request, string $serviceId): JsonResponse
  {
    try {
      $variations = $this->billService->getVariations($serviceId);

      return $this->success($variations, 'Variations retrieved.');
    } catch (\Throwable $e) {
      return $this->error('Could not fetch variations: ' . $e->getMessage());
    }
  }

  /**
   * POST /api/bills/verify
   * Verify a meter number or smart card before payment.
   */
  public function verify(Request $request): JsonResponse
  {
    $validated = $request->validate([
      'service_id'   => ['required', 'string'],
      'billers_code' => ['required', 'string'],
      'type'         => ['sometimes', 'nullable', 'string', 'in:prepaid,postpaid'],
    ]);

    try {
      $result = $this->billService->verify(
        $validated['service_id'],
        $validated['billers_code'],
        $validated['type'] ?? null
      );

      return $this->success($result, 'Verified successfully.');
    } catch (\Throwable $e) {
      return $this->error('Verification failed: ' . $e->getMessage());
    }
  }

  /**
   * POST /api/bills/pay
   * Pay a bill.
   */
  public function pay(Request $request): JsonResponse
  {
    $validated = $request->validate([
      'bill_type'      => ['required', 'string', 'in:airtime,data,electricity,cable'],
      'provider'       => ['required', 'string'],
      'amount'         => ['required', 'integer', 'min:50000'], // Min ₦500
      'phone'          => ['required_if:bill_type,airtime,data', 'nullable', 'string'],
      'biller_code'    => ['required_if:bill_type,electricity,cable', 'nullable', 'string'],
      'variation_code' => ['required_if:bill_type,data,cable', 'nullable', 'string'],
      'meter_type'     => ['sometimes', 'nullable', 'string', 'in:prepaid,postpaid'],
    ]);

    try {
      $payment = $this->billService->pay($request->user(), $validated);

      return $this->created(
        $this->formatPayment($payment),
        'Bill payment successful.'
      );
    } catch (\RuntimeException $e) {
      return $this->error($e->getMessage());
    } catch (\Throwable $e) {
      return $this->serverError('Bill payment failed. Please try again.');
    }
  }

  /**
   * GET /api/bills/history
   */
  public function history(Request $request): JsonResponse
  {
    $payments = $this->billService->history(
      $request->user(),
      $request->input('per_page', 20)
    );

    return $this->paginated(
      $payments->through(fn($p) => $this->formatPayment($p)),
      'Payment history retrieved.'
    );
  }

  /**
   * GET /api/bills/history/{id}
   */
  public function show(Request $request, string $id): JsonResponse
  {
    $payment = $request->user()->billPayments()->find($id);

    if (! $payment) {
      return $this->notFound('Payment not found.');
    }

    return $this->success($this->formatPayment($payment, true));
  }

  /**
   * GET /api/bills/providers
   * Returns all supported providers by bill type.
   */
  public function providers(): JsonResponse
  {
    return $this->success([
      'airtime' => [
        ['id' => 'mtn',    'name' => 'MTN',     'logo' => 'mtn'],
        ['id' => 'airtel', 'name' => 'Airtel',  'logo' => 'airtel'],
        ['id' => 'glo',    'name' => 'Glo',     'logo' => 'glo'],
        ['id' => '9mobile', 'name' => '9mobile', 'logo' => '9mobile'],
      ],
      'data' => [
        ['id' => 'mtn',    'name' => 'MTN Data',    'service_id' => 'mtn-data'],
        ['id' => 'airtel', 'name' => 'Airtel Data', 'service_id' => 'airtel-data'],
        ['id' => 'glo',    'name' => 'Glo Data',    'service_id' => 'glo-data'],
        ['id' => '9mobile', 'name' => '9mobile Data', 'service_id' => 'etisalat-data'],
      ],
      'electricity' => [
        ['id' => 'ikeja',  'name' => 'Ikeja Electric',  'service_id' => 'ikeja-electric'],
        ['id' => 'eko',    'name' => 'Eko Electric',    'service_id' => 'eko-electric'],
        ['id' => 'abuja',  'name' => 'Abuja Electric',  'service_id' => 'abuja-electric'],
        ['id' => 'kano',   'name' => 'Kano Electric',   'service_id' => 'kano-electric'],
        ['id' => 'phed',   'name' => 'PHED',            'service_id' => 'phed'],
        ['id' => 'enugu',  'name' => 'Enugu Electric',  'service_id' => 'enugu-electric'],
        ['id' => 'ibadan', 'name' => 'Ibadan Electric', 'service_id' => 'ibadan-electric'],
        ['id' => 'jos',    'name' => 'JOS Electric',    'service_id' => 'jos-electric'],
        ['id' => 'kaduna', 'name' => 'Kaduna Electric', 'service_id' => 'kaduna-electric'],
      ],
      'cable' => [
        ['id' => 'dstv',      'name' => 'DSTV',      'service_id' => 'dstv'],
        ['id' => 'gotv',      'name' => 'GOtv',      'service_id' => 'gotv'],
        ['id' => 'startimes', 'name' => 'Startimes', 'service_id' => 'startimes'],
      ],
    ], 'Providers retrieved.');
  }

  // ── Private helpers ───────────────────────────────────────────────────

  private function formatPayment(BillPayment $payment, bool $detailed = false): array
  {
    $base = [
      'id'                 => $payment->id,
      'bill_type'          => $payment->bill_type,
      'provider'           => $payment->provider,
      'amount'             => $payment->amount,
      'amount_formatted'   => '₦' . number_format($payment->amount / 100, 2),
      'status'             => $payment->status,
      'reference'          => $payment->reference,
      'token'              => $payment->token,
      'phone'              => $payment->phone,
      'biller_code'        => $payment->biller_code,
      'variation_code'     => $payment->variation_code,
      'paid_at'            => $payment->paid_at,
    ];

    if ($detailed) {
      $base['provider_reference'] = $payment->provider_reference;
      $base['response_data']      = $payment->response_data;
    }

    return $base;
  }
}
